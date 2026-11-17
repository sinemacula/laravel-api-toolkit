<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Events\ServiceCompleted;
use SineMacula\ApiToolkit\Services\Events\ServiceFailed;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceResult;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\CommitsRealTransactions;
use Tests\Fixtures\Services\ContextRecordingService;
use Tests\Fixtures\Services\InsertThenFailService;
use Tests\Fixtures\Services\Jobs\ContextReadingService;
use Tests\Fixtures\Services\SimpleService;
use Tests\Fixtures\Services\StubActor;
use Tests\Fixtures\Services\TransactionRecordingService;
use Tests\TestCase;

/**
 * Integration test for services run inside another service or a caller's own
 * transaction.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceRunner::class)]
final class ServiceCompositionTest extends TestCase
{
    use CommitsRealTransactions;

    /**
     * Reset the queued service's recorded context.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        ContextReadingService::reset();
    }

    /**
     * Test that an inner service failing inside an outer one rolls back only
     * its own savepoint.
     *
     * @return void
     */
    public function testInnerFailureRollsBackOnlyItsOwnWrite(): void
    {
        $outer = new TransactionRecordingService(static function (): ServiceResult {
            self::insertUser('outer');

            return (new InsertThenFailService)->run();
        });

        $result = $outer->run();

        self::assertTrue($result->succeeded());
        self::assertInstanceOf(ServiceResult::class, $result->output());
        self::assertTrue($result->output()->failed());
        self::assertSame(['outer@service.test'], DB::table('users')->pluck('email')->all());
    }

    /**
     * Test that an inner failure rethrown by runOrFail() rolls back both
     * services and fails the inner one first.
     *
     * @return void
     */
    public function testRethrownInnerFailureRollsBackBothServicesInnerFirst(): void
    {
        $failed = [];

        Event::listen(ServiceFailed::class, static function (ServiceFailed $event) use (&$failed): void {
            $failed[] = $event->service;
        });

        $outer = new TransactionRecordingService(static function (): void {
            self::insertUser('outer');

            (new InsertThenFailService)->runOrFail();
        });

        $result = $outer->run();

        self::assertInstanceOf(\RuntimeException::class, $result->exception);
        self::assertSame('Service handle failed after inserting a row.', $result->exception->getMessage());
        self::assertSame([InsertThenFailService::class, TransactionRecordingService::class], $failed);
        self::assertSame(0, DB::table('users')->count());
    }

    /**
     * Provide whether the nested service runs its own transaction.
     *
     * @return iterable<string, array{bool}>
     */
    public static function transactionalProvider(): iterable
    {
        yield 'transactional' => [true];
        yield 'not transactional' => [false];
    }

    /**
     * Test that a service succeeding inside a caller's transaction that rolls
     * back runs neither afterCommit(), its own after-commit callbacks nor
     * ServiceCompleted.
     *
     * @param  bool  $transactional
     * @return void
     */
    #[DataProvider('transactionalProvider')]
    public function testNestedSuccessRunsNoSideEffectsWhenTheCallerRollsBack(bool $transactional): void
    {
        $completed = $this->recordCompletedEvents();
        $callbacks = 0;
        $result    = null;

        $service = new TransactionRecordingService(static function () use (&$callbacks): string {
            self::insertUser('nested');

            DB::afterCommit(static function () use (&$callbacks): void {
                $callbacks++;
            });

            return 'done';
        }, transactional: $transactional);

        DB::beginTransaction();

        $result = $service->run();

        DB::rollBack();

        self::assertInstanceOf(ServiceResult::class, $result);
        self::assertTrue($result->succeeded());
        self::assertSame([], $result->sideEffectErrors());
        self::assertSame([], $service->commitLevels);
        self::assertSame(0, $callbacks);
        self::assertSame([], $completed->getArrayCopy());
        self::assertSame(0, DB::table('users')->count());
    }

    /**
     * Test that a service succeeding inside a caller's transaction runs
     * afterCommit() and ServiceCompleted once, after the caller commits, with
     * its context bound and its side-effect errors on the event.
     *
     * @param  bool  $transactional
     * @return void
     */
    #[DataProvider('transactionalProvider')]
    public function testNestedSuccessRunsItsSideEffectsOnceTheCallerCommits(bool $transactional): void
    {
        Exceptions::fake();

        $completed = $this->recordCompletedEvents();
        $context   = ServiceContext::for(new StubActor);
        $error     = new \RuntimeException('side effect failed');
        $result    = null;

        $service = (new TransactionRecordingService(static function (): string {
            self::insertUser('nested');

            return 'done';
        }, transactional: $transactional))->withContext($context);

        $service->onCommit = static fn (): never => throw $error;

        DB::transaction(static function () use ($service, &$result): void {
            $result = $service->run();

            self::assertSame([], $service->commitLevels);
        });

        self::assertInstanceOf(ServiceResult::class, $result);
        self::assertSame([], $result->sideEffectErrors());
        self::assertSame([0], $service->commitLevels);
        self::assertSame($context, $service->commitContext);
        self::assertCount(1, $completed);
        self::assertInstanceOf(ServiceCompleted::class, $completed[0]);
        self::assertSame($context->correlationId, $completed[0]->correlationId);
        self::assertSame([$error], $completed[0]->result->sideEffectErrors());
        self::assertSame(1, DB::table('users')->count());

        Exceptions::assertReported(static fn (\RuntimeException $e): bool => $e === $error);
    }

    /**
     * Test that a throwing ServiceCompleted listener of a nested service is
     * reported without failing the outer service or skipping later after-commit
     * callbacks.
     *
     * @return void
     */
    public function testNestedCompletedListenerThrowIsReportedAndLaterCallbacksStillRun(): void
    {
        Exceptions::fake();

        $error = new \RuntimeException('listener failed');
        $later = 0;

        Event::listen(ServiceCompleted::class, static function (ServiceCompleted $event) use ($error): void {
            if ($event->service === SimpleService::class) {
                throw $error;
            }
        });

        $outer = new TransactionRecordingService(static function () use (&$later): string {
            (new SimpleService)->run();

            DB::afterCommit(static function () use (&$later): void {
                $later++;
            });

            return 'outer';
        });

        $result = $outer->run();

        self::assertTrue($result->succeeded());
        self::assertSame([0], $outer->commitLevels);
        self::assertSame(1, $later);

        Exceptions::assertReported(static fn (\RuntimeException $e): bool => $e === $error);
    }

    /**
     * Test that a service dispatched from a nested afterCommit() is not run
     * when the caller rolls back.
     *
     * @return void
     */
    public function testDispatchFromNestedAfterCommitDoesNotRunWhenTheCallerRollsBack(): void
    {
        $service = $this->dispatchingService();

        DB::beginTransaction();

        $service->run();

        DB::rollBack();

        self::assertNull(ContextReadingService::$seen);
    }

    /**
     * Test that a service dispatched from a nested afterCommit() runs once the
     * caller commits.
     *
     * @return void
     */
    public function testDispatchFromNestedAfterCommitRunsOnceTheCallerCommits(): void
    {
        $service = $this->dispatchingService();

        DB::transaction(static function () use ($service): void {
            $service->run();

            self::assertNull(ContextReadingService::$seen);
        });

        self::assertSame([0], $service->commitLevels);
        self::assertSame(ServiceSource::QUEUE, ContextReadingService::$seen?->source);
    }

    /**
     * Test that an inner service gets its own context unless it is given the
     * outer one.
     *
     * @return void
     */
    public function testInnerServiceGetsItsOwnContextUnlessGivenTheOuterOne(): void
    {
        $context = ServiceContext::for(new StubActor);
        $own     = new ContextRecordingService(new ArrayInput([]));
        $shared  = (new ContextRecordingService(new ArrayInput([])))->withContext($context);

        $outer = (new TransactionRecordingService(static function () use ($own, $shared): null {
            $own->run();
            $shared->run();

            return null;
        }))->withContext($context);

        self::assertTrue($outer->run()->succeeded());
        self::assertSame(ServiceSource::INTERNAL, $own->seen['handle']->source);
        self::assertNotSame($context->correlationId, $own->seen['handle']->correlationId);
        self::assertSame($context, $shared->seen['handle']);
    }

    /**
     * Record every ServiceCompleted event a real listener receives.
     *
     * @return \ArrayObject<int, \SineMacula\ApiToolkit\Services\Events\ServiceCompleted>
     */
    private function recordCompletedEvents(): \ArrayObject
    {
        /** @var \ArrayObject<int, \SineMacula\ApiToolkit\Services\Events\ServiceCompleted> $events */
        $events = new \ArrayObject;

        Event::listen(ServiceCompleted::class, static function (ServiceCompleted $event) use ($events): void {
            $events->append($event);
        });

        return $events;
    }

    /**
     * Build a service whose afterCommit() dispatches a recording service on the
     * sync queue.
     *
     * @return \Tests\Fixtures\Services\TransactionRecordingService
     */
    private function dispatchingService(): TransactionRecordingService
    {
        Config::set('queue.default', 'sync');

        $service = new TransactionRecordingService(static fn (): string => 'done');

        $service->onCommit = static function (): void {
            ContextReadingService::make(new ArrayInput([]))->dispatch();
        };

        return $service;
    }

    /**
     * Insert a users row named after the given label.
     *
     * @param  string  $label
     * @return void
     */
    private static function insertUser(string $label): void
    {
        DB::table('users')->insert(['name' => $label, 'email' => $label . '@service.test']);
    }
}
