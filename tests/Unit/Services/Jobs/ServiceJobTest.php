<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Jobs;

use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Services\Jobs\ConcernCapturingService;
use Tests\Fixtures\Services\Jobs\ContextCapturingConcern;
use Tests\Fixtures\Services\StubActor;
use Tests\TestCase;

/**
 * Tests for the ServiceJob queue bridge.
 *
 * Covers payload serialisation, worker-side re-hydration and execution via
 * ServiceRunner, QUEUE source enforcement, and the absence of Auth or Request
 * consultation on the worker.
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
     * Reset the context-capturing concern before each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        ContextCapturingConcern::reset();
        app()->instance(ContextCapturingConcern::class, new ContextCapturingConcern);
    }

    /**
     * Test that the job payload survives a PHP serialise round trip.
     *
     * @return void
     */
    public function testPayloadSerialisesAndRestores(): void
    {
        // Arrange
        $input   = new ArrayInput(['key' => 'value']);
        $actor   = new StubActor;
        $context = ServiceContext::for($actor, ServiceSource::HTTP, [], 'corr-123');
        $job     = new ServiceJob(ConcernCapturingService::class, $input, $context);

        // Act
        $restored = unserialize(serialize($job));
        assert($restored instanceof ServiceJob);

        // Assert
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
        // Arrange
        $input   = new ArrayInput([]);
        $actor   = new StubActor;
        $context = ServiceContext::for($actor, ServiceSource::HTTP);
        $job     = new ServiceJob(ConcernCapturingService::class, $input, $context);

        // Act
        $job->handle(new ServiceRunner);

        // Assert - concern was invoked, proving the service was rebuilt and run
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
        // Arrange - assert Auth is never consulted
        Auth::shouldReceive('user')->never();
        Auth::shouldReceive('check')->never();
        Auth::shouldReceive('id')->never();
        Auth::shouldReceive('guest')->never();

        $input   = new ArrayInput([]);
        $actor   = new StubActor;
        $context = ServiceContext::for($actor, ServiceSource::HTTP, [], 'no-auth-corr');
        $job     = new ServiceJob(ConcernCapturingService::class, $input, $context);

        // Act
        $job->handle(new ServiceRunner);

        // Assert - source is QUEUE and actor came from the serialised context
        self::assertNotNull(ContextCapturingConcern::$captured);
        self::assertSame(ServiceSource::QUEUE, ContextCapturingConcern::$captured->source);
        self::assertSame('stub-id', ContextCapturingConcern::$captured->actor->actorIdentifier());
    }
}
