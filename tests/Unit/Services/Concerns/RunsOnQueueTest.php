<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Services\Actors\SystemActor;
use SineMacula\ApiToolkit\Services\Concerns\RunsOnQueue;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use Tests\Fixtures\Services\AuthorizingService;
use Tests\Fixtures\Services\ContextRecordingService;
use Tests\Fixtures\Services\Jobs\RefusingService;
use Tests\Fixtures\Services\OutputService;
use Tests\Fixtures\Services\StubActor;
use Tests\TestCase;

/**
 * Tests for queueing a service and relaying the queue's terminal failure.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(RunsOnQueue::class)]
final class RunsOnQueueTest extends TestCase
{
    /**
     * Fake the queue and reset the refusing fixture.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        RefusingService::reset();
    }

    /**
     * Test that dispatch() reuses the context supplied via withContext().
     *
     * @return void
     */
    public function testDispatchReusesContextSuppliedByWithContext(): void
    {
        $context = ServiceContext::for(new StubActor, ServiceSource::HTTP, [], 'fixed-correlation');

        (new OutputService(new ArrayInput([])))->withContext($context)->dispatch();

        Queue::assertPushed(ServiceJob::class, static fn (ServiceJob $job): bool => $job->context === $context);
    }

    /**
     * Test that dispatch() returns the pending dispatch of the service's job.
     *
     * @return void
     */
    public function testDispatchReturnsThePendingDispatchOfTheServiceJob(): void
    {
        $pending = (new OutputService(new ArrayInput([])))->dispatch();
        $job     = $pending->getJob();

        self::assertInstanceOf(ServiceJob::class, $job);
        self::assertSame(OutputService::class, $job->service);

        unset($pending);

        Queue::assertPushed(ServiceJob::class);
    }

    /**
     * Test that dispatch() runs authorize() and validate() with the job's
     * context and stops before handle().
     *
     * @return void
     */
    public function testDispatchRunsThePreflightWithTheJobsContext(): void
    {
        $context = ServiceContext::for(new StubActor, ServiceSource::HTTP);
        $service = new ContextRecordingService(new ArrayInput([]));

        $service->withContext($context)->dispatch();

        self::assertArrayNotHasKey('prepare', $service->seen);
        self::assertArrayNotHasKey('handle', $service->seen);
        self::assertSame($context, $service->seen['authorize']);
        self::assertSame($context, $service->seen['validate']);
        self::assertNull($service->bindRunContext(null));
    }

    /**
     * Provide the refusals the pre-flight throws.
     *
     * @return iterable<string, array{\Closure(): \Throwable}>
     */
    public static function refusalProvider(): iterable
    {
        yield 'authorization' => [static fn (): \Throwable => new AuthorizationException('Denied.')];
        yield 'validation' => [static fn (): \Throwable => ValidationException::withMessages(['name' => 'Invalid.'])];
    }

    /**
     * Test that a refusal throws from dispatch() and queues nothing.
     *
     * @param  \Closure(): \Throwable  $refusal
     * @return void
     */
    #[DataProvider('refusalProvider')]
    public function testDispatchThrowsARefusalAndQueuesNothing(\Closure $refusal): void
    {
        RefusingService::$refusal = $refusal();

        try {
            RefusingService::make(new ArrayInput([]))->dispatch();
            self::fail('A refused dispatch must throw.');
        } catch (\Throwable $exception) {
            self::assertSame(RefusingService::$refusal, $exception);
        }

        Queue::assertNothingPushed();
        self::assertSame(0, RefusingService::$failures);
    }

    /**
     * Test that dispatch() skips authorize() for a SystemActor.
     *
     * @return void
     */
    public function testDispatchSkipsAuthorizationForASystemActor(): void
    {
        (new AuthorizingService)->by(new SystemActor)->dispatch();

        Queue::assertPushed(ServiceJob::class);
    }

    /**
     * Test that toJob() runs no pre-flight and carries the default context.
     *
     * @return void
     */
    public function testToJobRunsNoPreflightAndCarriesTheDefaultContext(): void
    {
        $job = (new AuthorizingService)->by(new StubActor)->toJob();

        self::assertSame(AuthorizingService::class, $job->service);
        self::assertSame(ServiceSource::INTERNAL, $job->context->source);
        self::assertInstanceOf(StubActor::class, $job->context->actor);
    }

    /**
     * Test that the final-failure hook calls onFinalFailure().
     *
     * @return void
     */
    public function testFinalFailureHookCallsOnFinalFailure(): void
    {
        $service = new class (new ArrayInput([])) extends Service {
            /** @var \Throwable|null The exception onFinalFailure() received */
            public ?\Throwable $received = null;

            /**
             * Complete the run.
             *
             * @return null
             */
            #[\Override]
            protected function handle(): null
            {
                return null;
            }

            /**
             * Record the exception.
             *
             * @param  \Throwable  $exception
             * @return void
             */
            #[\Override]
            protected function onFinalFailure(\Throwable $exception): void
            {
                $this->received = $exception;
            }
        };

        $exception = new \RuntimeException('Gave up.');

        ($service->finalFailureHook())($exception);

        self::assertSame($exception, $service->received);
    }
}
