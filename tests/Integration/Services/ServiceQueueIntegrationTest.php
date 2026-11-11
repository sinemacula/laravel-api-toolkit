<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Actors\EloquentActor;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Actors\ActorUser;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Services\Jobs\ConcernCapturingService;
use Tests\Fixtures\Services\Jobs\ContextCapturingConcern;
use Tests\Fixtures\Services\Jobs\CountingFailingService;
use Tests\Fixtures\Services\StubActor;
use Tests\TestCase;

/**
 * Queue integration tests for ServiceJob and the actor serialisation path.
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
     * Register the actor morph alias and reset the static concern capture.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['actor_user' => ActorUser::class]);
        ContextCapturingConcern::reset();
        CountingFailingService::reset();
    }

    /**
     * Test that a queued run carries the actor without Auth in scope.
     *
     * @return void
     */
    public function testQueuedRunCarriesActorWithoutAuth(): void
    {
        $user  = ActorUser::create(['name' => 'Queue User', 'email' => 'queue@test.com']);
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

        $capturedActor = $captured->actor;

        self::assertSame($user->id, $capturedActor->actorIdentifier());
        self::assertSame('Queue User', $capturedActor->actorLabel());
    }

    /**
     * Test that the worker run reports the QUEUE source.
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

    /**
     * Test that a worker retries a failing service, then records the failure.
     *
     * @return void
     */
    public function testWorkerRetriesAFailingServiceThenRecordsTheFailure(): void
    {
        $this->useDatabaseQueue();

        Queue::push(new ServiceJob(CountingFailingService::class, new ArrayInput([]), ServiceContext::for(new StubActor)));

        $this->workOnce();

        self::assertSame(1, CountingFailingService::$runs);
        self::assertSame(1, DB::connection('queue_probe')->table('jobs')->count());
        self::assertSame(0, DB::connection('queue_probe')->table('failed_jobs')->count());

        $this->workOnce();

        self::assertSame(2, CountingFailingService::$runs);
        self::assertSame(0, DB::connection('queue_probe')->table('jobs')->count());

        $failed = DB::connection('queue_probe')->table('failed_jobs')->sole();

        self::assertIsString($failed->exception);
        self::assertStringStartsWith(ServiceExecutionException::class . ': ' . CountingFailingService::MESSAGE, $failed->exception);
    }

    /**
     * Test that a worker completes a succeeding service and records no failure.
     *
     * @return void
     */
    public function testWorkerCompletesASucceedingService(): void
    {
        $this->useDatabaseQueue();

        Queue::push(new ServiceJob(ConcernCapturingService::class, new ArrayInput([]), ServiceContext::for(new StubActor)));

        $this->workOnce();

        self::assertNotNull(ContextCapturingConcern::$captured);
        self::assertSame(0, DB::connection('queue_probe')->table('jobs')->count());
        self::assertSame(0, DB::connection('queue_probe')->table('failed_jobs')->count());
    }

    /**
     * Give the database queue a private connection, apart from the suite's.
     *
     * @return void
     */
    private function useDatabaseQueue(): void
    {
        Config::set('database.connections.queue_probe', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database', ['driver' => 'database', 'connection' => 'queue_probe', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        Config::set('queue.failed', ['driver' => 'database-uuids', 'database' => 'queue_probe', 'table' => 'failed_jobs']);

        Schema::connection('queue_probe')->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::connection('queue_probe')->create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /**
     * Process the next job on the database queue, allowing two attempts.
     *
     * @return void
     */
    private function workOnce(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 2, '--timeout' => 0]);
    }
}
