<?php

declare(strict_types = 1);

namespace Tests\Unit\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Concerns\CreatesDeferredWriteTargets;
use Tests\Concerns\SwapsExceptionHandler;
use Tests\TestCase;

/**
 * Tests for the WritePoolFlushSubscriber.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePoolFlushSubscriber::class)]
final class WritePoolFlushSubscriberTest extends TestCase
{
    use CreatesDeferredWriteTargets, SwapsExceptionHandler;

    /**
     * Set up the test environment.
     *
     * The flush's own cache invalidation is disabled so the subscriber is
     * observed in isolation.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', false);
    }

    /**
     * Provide the lifecycle end events the subscriber listens for.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function lifecycleEventProvider(): iterable
    {
        yield 'request handled' => [RequestHandled::class];
        yield 'command finished' => [CommandFinished::class];
        yield 'job attempted' => [JobAttempted::class];
        yield 'job timed out' => [JobTimedOut::class];
    }

    /**
     * Test that subscribe registers a listener for each lifecycle end event.
     *
     * @param  class-string  $event
     * @return void
     */
    #[DataProvider('lifecycleEventProvider')]
    public function testSubscribeRegistersListenerForLifecycleEvent(string $event): void
    {
        $events = new Dispatcher;

        (new WritePoolFlushSubscriber)->subscribe($events);

        self::assertTrue($events->hasListeners($event));
    }

    /**
     * Provide a factory for each lifecycle end event.
     *
     * @return iterable<string, array{\Closure(): object}>
     */
    public static function lifecycleEventInstanceProvider(): iterable
    {
        yield 'request handled' => [static fn (): object => new RequestHandled(Request::create('/test', 'GET'), new Response)];
        yield 'command finished' => [static fn (): object => new CommandFinished('test:command', new ArrayInput([]), new NullOutput, 0)];
        yield 'job attempted' => [static fn (): object => new JobAttempted('sync', self::createStub(Job::class))];
        yield 'job attempt that threw' => [static fn (): object => new JobAttempted('database', self::createStub(Job::class), new \RuntimeException('Job failure'))];
        yield 'job timed out' => [static fn (): object => new JobTimedOut('database', self::createStub(Job::class), 60)];
    }

    /**
     * Test that dispatching a lifecycle end event flushes the pool.
     *
     * @param  \Closure(): object  $event
     * @return void
     */
    #[DataProvider('lifecycleEventInstanceProvider')]
    public function testDispatchingLifecycleEventFlushesThePool(\Closure $event): void
    {
        [$events, $pool] = $this->subscribeWithPooledRow('lifecycle@example.com');

        $events->dispatch($event());

        self::assertTrue($pool->isEmpty());
    }

    /**
     * Test that handleFlush flushes the pool of the current container, not the
     * one the subscriber was built in, as under an Octane request sandbox.
     *
     * @return void
     */
    public function testHandleFlushFlushesThePoolOfTheCurrentContainer(): void
    {
        $app = $this->app;

        assert($app !== null);

        $basePool = new WritePool(500, 10000);
        $basePool->add('testing', 'users', ['name' => 'Base', 'email' => 'base@example.com', 'password' => 'secret']);
        $app->instance(WritePool::class, $basePool);

        $subscriber = $app->make(WritePoolFlushSubscriber::class);

        $sandbox     = clone $app;
        $sandboxPool = new WritePool(500, 10000);
        $sandboxPool->add('testing', 'users', ['name' => 'Sandbox', 'email' => 'sandbox@example.com', 'password' => 'secret']);
        $sandbox->instance(WritePool::class, $sandboxPool);

        Container::setInstance($sandbox);

        try {
            $subscriber->handleFlush();
        } finally {
            Container::setInstance($app);
        }

        self::assertTrue($sandboxPool->isEmpty());
        self::assertSame(1, $basePool->count());
        self::assertSame(['sandbox@example.com'], DB::table('users')->pluck('email')->all());
    }

    /**
     * Test that a collected flush failure is reported once, chained to the
     * database exception with the table.
     *
     * @return void
     */
    public function testHandleFlushReportsCollectedFailureOnce(): void
    {
        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool())->handleFlush();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (WritePoolFlushException $e): bool => $e->getMessage() === 'WritePool flush failed: 1 chunk(s) failed out of 1 total.'
            && $e->getPrevious() instanceof QueryException
            && $e->getPrevious()       === $e->flushResult()->firstException()
            && $e->context()['tables'] === ['testing' => ['nonexistent_table']]);
    }

    /**
     * Test that handleFlush dispatches WritePoolFlushFailed event on failure.
     *
     * @return void
     */
    public function testHandleFlushDispatchesWritePoolFlushFailedEventOnFailure(): void
    {
        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool())->handleFlush();

        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult->failureCount() === 1);
    }

    /**
     * Test that a systemic COLLECT failure is reported once and dispatches an
     * event carrying every table.
     *
     * @return void
     */
    public function testHandleFlushDispatchesEventCarryingEverySystemicFailure(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('testing', 'nonexistent_table', ['col' => 'a']);
        $pool->add('testing', 'another_missing_table', ['col' => 'b']);

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($pool)->handleFlush();

        Exceptions::assertReportedCount(1);
        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult->failureCount() === 2
            && array_key_exists('nonexistent_table', $event->flushResult->failures()['testing'])
            && array_key_exists('another_missing_table', $event->flushResult->failures()['testing']));
    }

    /**
     * Test that a collected failure is reported, not thrown, even with
     * rethrow_at_boundary enabled.
     *
     * @return void
     */
    public function testHandleFlushReportsCollectedFailureWhenRethrowEnabled(): void
    {
        Config::set('api-toolkit.deferred_writes.rethrow_at_boundary', true);

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool())->handleFlush();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(WritePoolFlushException::class);
        Event::assertDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that a log strategy failure dispatches the event without reporting.
     *
     * @return void
     */
    public function testHandleFlushDoesNotReportLogStrategyFailure(): void
    {
        Log::shouldReceive('warning')->once();

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool(FlushStrategy::LOG))->handleFlush();

        Exceptions::assertNothingReported();
        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult->droppedRecordCount() === 1);
    }

    /**
     * Test that handleFlush neither reports nor dispatches on success.
     *
     * @return void
     */
    public function testHandleFlushDoesNotReportOrDispatchOnSuccess(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('testing', 'users', ['name' => 'Carol', 'email' => 'carol@example.com', 'password' => 'secret']);

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($pool)->handleFlush();

        Exceptions::assertNothingReported();
        Event::assertNotDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that a failed event dispatch after a collected failure is reported,
     * not thrown.
     *
     * @return void
     */
    public function testHandleFlushReportsEventDispatchFailureUnderCollect(): void
    {
        Exceptions::fake();

        Event::shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('Event dispatch failed'));

        $this->subscriberFlushing($this->failingPool())->handleFlush();

        Exceptions::assertReportedCount(2);
        Exceptions::assertReported(WritePoolFlushException::class);
        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e->getMessage() === 'Event dispatch failed');
    }

    /**
     * Test that a failed event dispatch after a throw strategy failure is
     * reported, not thrown.
     *
     * @return void
     */
    public function testHandleFlushReportsEventDispatchFailureUnderThrow(): void
    {
        Exceptions::fake();

        Event::shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('Event dispatch failed'));

        $this->subscriberFlushing($this->failingPool(FlushStrategy::THROW))->handleFlush();

        Exceptions::assertReportedCount(2);
        Exceptions::assertReported(WritePoolFlushException::class);
        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e->getMessage() === 'Event dispatch failed');
    }

    /**
     * Test that when reporting the flush failure fails, it falls back to the
     * error log and still dispatches.
     *
     * @return void
     */
    public function testHandleFlushStillDispatchesWhenReportingFails(): void
    {
        $handler = $this->useThrowingExceptionHandler();

        Log::shouldReceive('error')
            ->once()
            ->with('WritePool boundary flush failed', \Mockery::on(
                static fn (array $context): bool => $context['exception'] instanceof WritePoolFlushException,
            ));

        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool())->handleFlush();

        self::assertCount(1, $handler->reported);
        Event::assertDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that an unexpected throwable during pool resolution is reported
     * without crashing the boundary.
     *
     * @return void
     */
    public function testHandleFlushReportsWhenPoolResolutionThrows(): void
    {
        $this->app->bind(WritePool::class, static fn (): never => throw new \RuntimeException('Database connection lost')); // @phpstan-ignore method.nonObject

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        (new WritePoolFlushSubscriber)->handleFlush();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (\RuntimeException $e): bool => $e->getMessage() === 'Database connection lost');
        Event::assertNotDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that a throw strategy exception is reported once as the raised
     * instance and the event dispatched.
     *
     * @return void
     */
    public function testHandleFlushReportsThrowStrategyFailureOnce(): void
    {
        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool(FlushStrategy::THROW))->handleFlush();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (WritePoolFlushException $e): bool => $e->getPrevious() instanceof QueryException
            && $e->getPrevious()       === $e->flushResult()->firstException()
            && basename($e->getFile()) === 'WritePool.php');
        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult->failureCount() === 1);
    }

    /**
     * Test that the subscriber reports rather than rethrows when
     * rethrow_at_boundary is disabled.
     *
     * @return void
     */
    public function testHandleFlushDoesNotRethrowWhenRethrowDisabled(): void
    {
        Config::set('api-toolkit.deferred_writes.rethrow_at_boundary', false);

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->subscriberFlushing($this->failingPool(FlushStrategy::THROW))->handleFlush();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(WritePoolFlushException::class);
        Event::assertDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that the subscriber fails when rethrow_at_boundary is absent, rather
     * than guessing whether to rethrow.
     *
     * @return void
     */
    public function testHandleFlushFailsWhenRethrowKeyAbsent(): void
    {
        $deferred = (array) Config::get('api-toolkit.deferred_writes');
        unset($deferred['rethrow_at_boundary']);
        Config::set('api-toolkit.deferred_writes', $deferred);

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.deferred_writes.rethrow_at_boundary setting is missing; add it from the published config.');

        $this->subscriberFlushing($this->failingPool(FlushStrategy::THROW))->handleFlush();
    }

    /**
     * Test that the subscriber rethrows after dispatching the event when
     * rethrow_at_boundary is enabled.
     *
     * @return void
     */
    public function testHandleFlushRethrowsWithoutReportingWhenRethrowEnabled(): void
    {
        Config::set('api-toolkit.deferred_writes.rethrow_at_boundary', true);

        Exceptions::fake();
        Event::fake([WritePoolFlushFailed::class]);

        $thrown = null;

        try {
            $this->subscriberFlushing($this->failingPool(FlushStrategy::THROW))->handleFlush();
        } catch (WritePoolFlushException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(WritePoolFlushException::class, $thrown);
        self::assertInstanceOf(QueryException::class, $thrown->getPrevious());
        Exceptions::assertNothingReported();
        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult === $thrown->flushResult());
    }

    /**
     * Test that a deadlock ending a transaction the boundary flush joined is
     * rethrown unreported, so the transaction's retry writes the row once.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testHandleFlushRethrowsADeadlockEndingTheTransactionItJoinedUnreported(): void
    {
        Exceptions::fake();

        $pool       = $this->poolDeadlockingOnTheAuditConnection();
        $subscriber = $this->subscriberFlushing($pool);
        $attempts   = 0;

        DB::connection('audit')->transaction(static function () use ($pool, $subscriber, &$attempts): void {
            $attempts++;

            $pool->add('audit', 'audit_entries', ['action' => 'joined']);
            $subscriber->handleFlush();
        }, 2);

        self::assertSame(2, $attempts);
        self::assertSame(['joined'], DB::connection('audit')->table('audit_entries')->pluck('action')->all());
        Exceptions::assertNothingReported();
    }

    /**
     * Test that a deadlock ending a transaction the flush after a job timeout
     * joined is reported instead, so the worker still stops the job.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testHandleTimedOutFlushReportsADeadlockEndingTheTransactionItJoined(): void
    {
        Exceptions::fake();

        $pool       = $this->poolDeadlockingOnTheAuditConnection();
        $subscriber = $this->subscriberFlushing($pool);

        DB::connection('audit')->transaction(static function () use ($pool, $subscriber): void {
            $pool->add('audit', 'audit_entries', ['action' => 'timed out']);
            $subscriber->handleTimedOutFlush();
        });

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(DeadlockException::class);
        self::assertTrue($pool->isEmpty());
    }

    /**
     * Build a pool whose first insert on the audit connection deadlocks.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePool
     */
    private function poolDeadlockingOnTheAuditConnection(): WritePool
    {
        $this->useAuditConnection();

        $inserts = 0;

        DB::connection('audit')->beforeExecuting(static function (string $query) use (&$inserts): void {
            if (str_starts_with($query, 'insert') && ++$inserts === 1) {
                throw new QueryException('audit', $query, [], new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'));
            }
        });

        return new WritePool(500, 10000);
    }

    /**
     * Build a pool holding one row bound for a table that does not exist.
     *
     * @param  \SineMacula\ApiToolkit\Enums\FlushStrategy  $strategy
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\WritePool
     */
    private function failingPool(FlushStrategy $strategy = FlushStrategy::COLLECT): WritePool
    {
        $pool = new WritePool(500, 10000, $strategy);
        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);

        return $pool;
    }

    /**
     * Build a subscriber whose container resolves the given pool.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePool  $pool
     * @return \SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber
     */
    private function subscriberFlushing(WritePool $pool): WritePoolFlushSubscriber
    {
        $this->app->instance(WritePool::class, $pool); // @phpstan-ignore method.nonObject

        return new WritePoolFlushSubscriber;
    }

    /**
     * Subscribe a fresh dispatcher with a pool containing one pending row.
     *
     * @param  string  $email
     * @return array{0: \Illuminate\Events\Dispatcher, 1: \SineMacula\ApiToolkit\Repositories\Concerns\WritePool}
     */
    private function subscribeWithPooledRow(string $email): array
    {
        $pool = new WritePool(500, 10000);
        $pool->add('testing', 'users', ['name' => 'Dave', 'email' => $email, 'password' => 'secret']);

        $events = new Dispatcher;

        $this->subscriberFlushing($pool)->subscribe($events);

        return [$events, $pool];
    }
}
