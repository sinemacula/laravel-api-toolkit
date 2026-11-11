<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Jobs;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Services\Jobs\ConcernCapturingService;
use Tests\Fixtures\Services\Jobs\ContextCapturingConcern;
use Tests\Fixtures\Services\Jobs\ContextReadingService;
use Tests\Fixtures\Services\Jobs\CountingFailingService;
use Tests\Fixtures\Services\StubActor;
use Tests\TestCase;

/**
 * Tests for the ServiceJob queue bridge.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceJob::class)]
final class ServiceJobTest extends TestCase
{
    /**
     * Reset the context-capturing fixtures before each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        ContextCapturingConcern::reset();
        ContextReadingService::reset();
        CountingFailingService::reset();
        app()->instance(ContextCapturingConcern::class, new ContextCapturingConcern);
    }

    /**
     * Test that the job payload survives a PHP serialise round trip.
     *
     * @return void
     */
    public function testPayloadSerialisesAndRestores(): void
    {
        $input   = new ArrayInput(['key' => 'value']);
        $actor   = new StubActor;
        $context = ServiceContext::for($actor, ServiceSource::HTTP, [], 'corr-123');
        $job     = new ServiceJob(ConcernCapturingService::class, $input, $context);

        $restored = unserialize(serialize($job));
        assert($restored instanceof ServiceJob);

        self::assertSame(ConcernCapturingService::class, $restored->service);
        self::assertSame(['key' => 'value'], $restored->input->toArray());
        self::assertSame('corr-123', $restored->context->correlationId);
        self::assertSame('stub-id', $restored->context->actor->actorIdentifier());
    }

    /**
     * Test that handle() rebuilds the service and runs it via the runner.
     *
     * @return void
     */
    public function testHandleRebuildsAndRunsViaRunner(): void
    {
        $input   = new ArrayInput([]);
        $actor   = new StubActor;
        $context = ServiceContext::for($actor, ServiceSource::HTTP);
        $job     = new ServiceJob(ConcernCapturingService::class, $input, $context);

        $job->handle(new ServiceRunner);

        self::assertNotNull(ContextCapturingConcern::$captured);
        self::assertSame(ServiceSource::QUEUE, ContextCapturingConcern::$captured->source);
    }

    /**
     * Test that the worker run uses source QUEUE and reads no ambient Auth.
     *
     * @return void
     */
    public function testWorkerRunUsesQueueSourceAndNoAuth(): void
    {
        Auth::shouldReceive('user')->never();
        Auth::shouldReceive('check')->never();
        Auth::shouldReceive('id')->never();
        Auth::shouldReceive('guest')->never();

        $input   = new ArrayInput([]);
        $actor   = new StubActor;
        $context = ServiceContext::for($actor, ServiceSource::HTTP, [], 'no-auth-corr');
        $job     = new ServiceJob(ConcernCapturingService::class, $input, $context);

        $job->handle(new ServiceRunner);

        self::assertNotNull(ContextCapturingConcern::$captured);
        self::assertSame(ServiceSource::QUEUE, ContextCapturingConcern::$captured->source);
        self::assertSame('stub-id', ContextCapturingConcern::$captured->actor->actorIdentifier());
    }

    /**
     * Test that a queued service reads the QUEUE context through context().
     *
     * @return void
     */
    public function testQueuedServiceReadsTheQueueContext(): void
    {
        $metadata = ['ip' => '203.0.113.7', 'user_agent' => 'client/1.0'];
        $context  = ServiceContext::for(new StubActor, ServiceSource::HTTP, $metadata, 'queued-corr');
        $job      = new ServiceJob(ContextReadingService::class, new ArrayInput([]), $context);

        $job->handle(new ServiceRunner);

        $seen = ContextReadingService::$seen;

        self::assertNotNull($seen);
        self::assertSame(ServiceSource::QUEUE, $seen->source);
        self::assertSame('stub-id', $seen->actor->actorIdentifier());
        self::assertSame('queued-corr', $seen->correlationId);
        self::assertSame($metadata, $seen->metadata);
    }

    /**
     * Test that handle() rethrows the exception the failed run captured.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testHandleThrowsTheExceptionTheFailedRunCaptured(): void
    {
        $context = ServiceContext::for(new StubActor, ServiceSource::HTTP);
        $job     = new ServiceJob(CountingFailingService::class, new ArrayInput([]), $context);

        try {
            $job->handle(new ServiceRunner);
            self::fail('A failed run must fail the job.');
        } catch (ServiceExecutionException $exception) {
            self::assertSame(CountingFailingService::MESSAGE, $exception->getMessage());
            self::assertSame(1, CountingFailingService::$runs);
        }
    }

    /**
     * Test that a failing service fails the job on the sync queue.
     *
     * @return void
     */
    public function testSyncQueueFailsTheJobWhenTheServiceFails(): void
    {
        $failures = [];

        Queue::failing(static function (JobFailed $event) use (&$failures): void {
            $failures[] = $event->exception;
        });

        $context = ServiceContext::for(new StubActor);

        try {
            Queue::connection('sync')->push(new ServiceJob(CountingFailingService::class, new ArrayInput([]), $context));
            self::fail('A failed run must fail the job.');
        } catch (ServiceExecutionException $exception) {
            self::assertSame([$exception], $failures);
            self::assertSame(CountingFailingService::MESSAGE, $exception->getMessage());
        }
    }

    /**
     * Test that a succeeding service completes the job on the sync queue.
     *
     * @return void
     */
    public function testSyncQueueCompletesTheJobWhenTheServiceSucceeds(): void
    {
        $failures = 0;

        Queue::failing(static function () use (&$failures): void {
            $failures++;
        });

        $context = ServiceContext::for(new StubActor);

        Queue::connection('sync')->push(new ServiceJob(ConcernCapturingService::class, new ArrayInput([]), $context));

        self::assertSame(0, $failures);
        self::assertNotNull(ContextCapturingConcern::$captured);
    }
}
