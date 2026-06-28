<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Actors\EloquentActor;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Actors\ActorUser;
use Tests\Fixtures\Services\Jobs\ConcernCapturingService;
use Tests\Fixtures\Services\Jobs\ContextCapturingConcern;
use Tests\TestCase;

/**
 * Queue integration tests for ServiceJob and the actor serialisation path.
 *
 * Proves that a queued run re-hydrates identically, the actor survives
 * serialisation without consulting Auth or Request (NFR-07, AC-36), and the
 * worker run carries source = QUEUE.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ServiceJob::class)]
#[CoversClass(Service::class)]
final class ServiceQueueIntegrationTest extends TestCase
{
    /**
     * Set up the test environment.
     *
     * Registers the ActorUser morph alias so EloquentActor can re-resolve the
     * model after deserialisation, and resets the static concern capture.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['actor_user' => ActorUser::class]);
        ContextCapturingConcern::reset();
    }

    /**
     * Test that a queued run carries the actor without Auth in scope.
     *
     * No Auth::login() is called anywhere in this test. The actor travels
     * exclusively via PHP serialisation through the ServiceJob payload. After
     * the worker executes, the re-hydrated actor retains its identifier and
     * label snapshot from construction time.
     *
     * @return void
     */
    public function testQueuedRunCarriesActorWithoutAuth(): void
    {
        $user  = ActorUser::create(['name' => 'Queue User', 'email' => 'queue@test.com']);
        $actor = EloquentActor::for($user);

        $context = ServiceContext::for($actor);
        $job     = new ServiceJob(ConcernCapturingService::class, new ArrayInput([]), $context);

        // Serialise and deserialise to simulate the full queue worker path
        $serialized   = serialize($job);
        $deserialized = unserialize($serialized);
        assert($deserialized instanceof ServiceJob);

        assert($this->app !== null);
        $runner = $this->app->make(ServiceRunner::class);

        $deserialized->handle($runner);

        $captured = ContextCapturingConcern::$captured;

        self::assertNotNull($captured);

        $capturedActor = $captured->actor;

        self::assertSame($user->id, $capturedActor->actorIdentifier());
        self::assertSame('Queue User', $capturedActor->actorLabel());
    }

    /**
     * Test that the worker run reports the QUEUE source.
     *
     * ServiceJob::handle() forces source = QUEUE on the context it hands to
     * ServiceRunner, so the pipeline and any downstream consumers always know
     * the invocation came from a queue worker.
     *
     * @return void
     */
    public function testWorkerRunUsesQueueSource(): void
    {
        $user  = ActorUser::create(['name' => 'Source User', 'email' => 'source@test.com']);
        $actor = EloquentActor::for($user);

        $context = ServiceContext::for($actor);
        $job     = new ServiceJob(ConcernCapturingService::class, new ArrayInput([]), $context);

        $serialized   = serialize($job);
        $deserialized = unserialize($serialized);
        assert($deserialized instanceof ServiceJob);

        assert($this->app !== null);
        $runner = $this->app->make(ServiceRunner::class);

        $deserialized->handle($runner);

        $captured = ContextCapturingConcern::$captured;

        self::assertNotNull($captured);
        self::assertSame(ServiceSource::QUEUE, $captured->source);
    }
}
