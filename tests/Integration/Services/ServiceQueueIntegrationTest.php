<?php

declare(strict_types = 1);

namespace Tests\Integration\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use SineMacula\ApiToolkit\Services\Actors\EloquentActor;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Events\ServiceFailed;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\UsesDatabaseQueue;
use Tests\Fixtures\Actors\ActorUser;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Services\Input\BackedEnumInput;
use Tests\Fixtures\Services\Input\SensitiveInput;
use Tests\Fixtures\Services\Jobs\ConcernCapturingService;
use Tests\Fixtures\Services\Jobs\ContextCapturingConcern;
use Tests\Fixtures\Services\Jobs\ContextReadingService;
use Tests\Fixtures\Services\Jobs\CountingFailingService;
use Tests\Fixtures\Services\Jobs\EncryptedService;
use Tests\Fixtures\Services\Jobs\RefusingService;
use Tests\Fixtures\Services\Jobs\ReportQueueService;
use Tests\Fixtures\Services\Jobs\SingleAttemptService;
use Tests\Fixtures\Services\Jobs\TimingOutService;
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
    use UsesDatabaseQueue;

    /** @var string The secret the sensitive payload carries */
    private const string SECRET = 'hidden-password';

    /** @var array<int, array{string, string}> The queue and name of each job a worker processed */
    private array $processed = [];

    /** @var int The number of ServiceFailed events fired */
    private int $failedEvents = 0;

    /**
     * Register the actor morph alias and reset the recording fixtures.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['actor_user' => ActorUser::class]);
        ContextCapturingConcern::reset();
        ContextReadingService::reset();
        CountingFailingService::reset();
        RefusingService::reset();
        SingleAttemptService::reset();
    }

    /**
     * Test that a queued run carries the actor without Auth in scope.
     *
     * @return void
     */
    public function testQueuedRunCarriesActorWithoutAuth(): void
    {
        $user = ActorUser::create(['name' => 'Queue User', 'email' => 'queue@test.com']);

        $this->runRestored(ConcernCapturingService::make(new ArrayInput([]))->by(EloquentActor::for($user))->toJob());

        $captured = ContextCapturingConcern::$captured;

        self::assertNotNull($captured);
        self::assertSame($user->id, $captured->actor->actorIdentifier());
        self::assertSame('Queue User', $captured->actor->actorLabel());
    }

    /**
     * Test that the worker run reports the QUEUE source.
     *
     * @return void
     */
    public function testWorkerRunUsesQueueSource(): void
    {
        $user = ActorUser::create(['name' => 'Source User', 'email' => 'source@test.com']);

        $this->runRestored(ConcernCapturingService::make(new ArrayInput([]))->by(EloquentActor::for($user))->toJob());

        self::assertSame(ServiceSource::QUEUE, ContextCapturingConcern::$captured?->source);
    }

    /**
     * Test that a worker retries a failing service, running the failure hooks
     * and firing ServiceFailed on every attempt, then records the failure and
     * calls onFinalFailure() once.
     *
     * @return void
     */
    public function testWorkerRetriesAFailingServiceThenRecordsTheFailure(): void
    {
        $this->useDatabaseQueue();

        Event::listen(ServiceFailed::class, function (): void {
            $this->failedEvents++;
        });

        CountingFailingService::make(new ArrayInput([]))->by(new StubActor)->dispatch();

        $this->workOnce();

        self::assertSame(['runs' => 1, 'onFailure' => 1, 'ServiceFailed' => 1, 'onFinalFailure' => 0], $this->attemptCounts());
        self::assertSame(1, DB::connection('queue_probe')->table('jobs')->count());
        self::assertSame(0, DB::connection('queue_probe')->table('failed_jobs')->count());

        $this->workOnce();

        self::assertSame(['runs' => 2, 'onFailure' => 2, 'ServiceFailed' => 2, 'onFinalFailure' => 1], $this->attemptCounts());
        self::assertSame(0, DB::connection('queue_probe')->table('jobs')->count());

        $failed = DB::connection('queue_probe')->table('failed_jobs')->sole();

        self::assertIsString($failed->exception);
        self::assertStringStartsWith(ServiceExecutionException::class . ': ' . CountingFailingService::MESSAGE, $failed->exception);
    }

    /**
     * Test that a declared #[Tries] overrides the worker's --tries.
     *
     * @return void
     */
    public function testDeclaredTriesOverrideTheWorkersTries(): void
    {
        $this->useDatabaseQueue();

        SingleAttemptService::make(new ArrayInput([]))->dispatch();

        $this->workOnce(tries: 2);

        self::assertSame(1, SingleAttemptService::$runs);
        self::assertCount(1, SingleAttemptService::$finalFailures);
        self::assertSame(1, DB::connection('queue_probe')->table('failed_jobs')->count());
    }

    /**
     * Test that a worker completes a succeeding service and records no failure.
     *
     * @return void
     */
    public function testWorkerCompletesASucceedingService(): void
    {
        $this->useDatabaseQueue();

        ConcernCapturingService::make(new ArrayInput([]))->by(new StubActor)->dispatch();

        $this->workOnce();

        self::assertNotNull(ContextCapturingConcern::$captured);
        self::assertSame(0, DB::connection('queue_probe')->table('jobs')->count());
        self::assertSame(0, DB::connection('queue_probe')->table('failed_jobs')->count());
    }

    /**
     * Test that the pushed payload names the service rather than the job.
     *
     * @return void
     */
    public function testPushedPayloadIsNamedAfterTheService(): void
    {
        $this->useDatabaseQueue();

        ContextReadingService::make(new ArrayInput([]))->dispatch();

        self::assertSame(ContextReadingService::class, $this->decodedPayload()['displayName']);
    }

    /**
     * Test that a per-call queue beats the declared one, which beats the
     * service's route, which beats the job's route.
     *
     * @return void
     */
    public function testDispatchFollowsTheQueuePrecedence(): void
    {
        Queue::fake();
        Queue::route(ServiceJob::class, 'job-route');

        ContextReadingService::make(new ArrayInput([]))->dispatch();

        Queue::route(ContextReadingService::class, 'service-route');
        Queue::route(ReportQueueService::class, 'service-route');

        ContextReadingService::make(new ArrayInput([]))->dispatch();
        ReportQueueService::make(new ArrayInput([]))->dispatch();
        ReportQueueService::make(new ArrayInput([]))->dispatch()->onQueue('per-call');

        $queues = array_column(Queue::pushedJobs()[ServiceJob::class], 'queue');

        self::assertSame(['job-route', 'service-route', ReportQueueService::QUEUE, 'per-call'], $queues);
    }

    /**
     * Test that a payload's sensitive value is encrypted in the stored job and
     * stays out of the failed job store.
     *
     * @return void
     */
    public function testSensitivePayloadValueIsEncryptedInTheStoredAndFailedJob(): void
    {
        $this->useDatabaseQueue();
        $this->useEncryptionKey();

        CountingFailingService::make(new SensitiveInput('reader', self::SECRET))->dispatch();

        self::assertStringNotContainsString(self::SECRET, $this->storedPayload('jobs'));

        $job = $this->decryptedJob();

        self::assertInstanceOf(SensitiveInput::class, $job->input);
        self::assertSame(self::SECRET, $job->input->password);

        $this->workOnce();
        $this->workOnce();

        self::assertSame(1, DB::connection('queue_probe')->table('failed_jobs')->count());
        self::assertStringNotContainsString(self::SECRET, $this->storedPayload('failed_jobs'));
    }

    /**
     * Test that a ShouldBeEncrypted service is stored encrypted.
     *
     * @return void
     */
    public function testShouldBeEncryptedServiceIsStoredEncrypted(): void
    {
        $this->useDatabaseQueue();
        $this->useEncryptionKey();

        EncryptedService::make(new ArrayInput([]))->dispatch();

        self::assertSame(EncryptedService::class, $this->decryptedJob()->service);
    }

    /**
     * Test that a payload without a sensitive parameter is stored as is.
     *
     * @return void
     */
    public function testPlainPayloadIsStoredUnencrypted(): void
    {
        $this->useDatabaseQueue();

        ContextReadingService::make(new BackedEnumInput)->dispatch();

        self::assertInstanceOf(ServiceJob::class, unserialize($this->storedCommand()));
    }

    /**
     * Test that a failure on the sync connection is thrown to the code that
     * dispatched it.
     *
     * @return void
     */
    public function testSyncDispatchThrowsToTheCaller(): void
    {
        Config::set('queue.default', 'sync');

        $this->assertThrows(
            static fn () => CountingFailingService::make(new ArrayInput([]))->dispatch(),
            ServiceExecutionException::class,
            CountingFailingService::MESSAGE,
        );

        self::assertSame(1, CountingFailingService::$runs);
        self::assertCount(1, CountingFailingService::$finalFailures);
    }

    /**
     * Test that a refusal on the sync connection is thrown to the caller and
     * calls onFinalFailure() once.
     *
     * @return void
     */
    public function testSyncRefusalThrowsToTheCallerAndFinalFailureFiresOnce(): void
    {
        Config::set('queue.default', 'sync');

        RefusingService::$refusal = new AuthorizationException('Denied.');

        $this->assertThrows(
            static fn () => Bus::dispatch(RefusingService::make(new ArrayInput([]))->toJob()),
            AuthorizationException::class,
        );

        self::assertSame(1, RefusingService::$failures);
        self::assertSame([RefusingService::$refusal], RefusingService::$finalFailures);
    }

    /**
     * Test that a model nested in the input is not re-fetched on the worker.
     *
     * @return void
     */
    public function testNestedModelIsNotRefetchedOnTheWorker(): void
    {
        $this->useDatabaseQueue();

        $user = ActorUser::create(['name' => 'Dispatched', 'email' => 'nested@test.com']);

        ContextReadingService::make(new ArrayInput(['user' => $user]))->dispatch();

        $user->update(['name' => 'Renamed']);

        $this->workOnce();

        $nested = ContextReadingService::$seenInput?->toArray()['user'] ?? null;

        self::assertInstanceOf(ActorUser::class, $nested);
        self::assertSame('Dispatched', $nested->name);
    }

    /**
     * Test that a timed-out final attempt reaches onFinalFailure() with the
     * timeout and never onFailure(), as the worker kills itself mid-run.
     *
     * @return void
     */
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testTimedOutFinalAttemptReachesOnlyOnFinalFailure(): void
    {
        $this->useDatabaseQueue();

        TimingOutService::$log = (string) tempnam(sys_get_temp_dir(), 'service-timeout-');

        TimingOutService::make(new ArrayInput([]))->dispatch();

        $pid = pcntl_fork();

        if ($pid === 0) {
            try {
                $this->workUntilEmpty(tries: 1);
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        pcntl_waitpid($pid, $status);

        $log = (string) file_get_contents(TimingOutService::$log);

        unlink(TimingOutService::$log);

        self::assertSame('onFinalFailure ' . TimeoutExceededException::class . PHP_EOL, $log);
    }

    /**
     * Test that the second service of a chain runs on its declared queue.
     *
     * @return void
     */
    public function testChainedServiceRunsOnItsDeclaredQueue(): void
    {
        $this->useDatabaseQueue();
        $this->recordProcessedJobs();

        Bus::chain([
            ContextReadingService::make(new ArrayInput([]))->toJob(),
            ReportQueueService::make(new ArrayInput([]))->toJob(),
        ])->dispatch();

        $this->workUntilEmpty();
        $this->workUntilEmpty(queue: ReportQueueService::QUEUE);

        self::assertSame([['default', ContextReadingService::class], [ReportQueueService::QUEUE, ReportQueueService::class]], $this->processed);
    }

    /**
     * Test that a refusal in a chain stops the services after it.
     *
     * @return void
     */
    public function testRefusalStopsTheChain(): void
    {
        $this->useDatabaseQueue();

        RefusingService::$refusal = new AuthorizationException('Denied.');

        Bus::chain([
            RefusingService::make(new ArrayInput([]))->toJob(),
            ContextReadingService::make(new ArrayInput([]))->toJob(),
        ])->dispatch();

        $this->workUntilEmpty();

        self::assertNull(ContextReadingService::$seen);
        self::assertSame(0, DB::connection('queue_probe')->table('jobs')->count());
        self::assertSame(1, DB::connection('queue_probe')->table('failed_jobs')->count());
    }

    /**
     * Test that a chain inside a batch runs its first service on the batch's
     * queue, and each later one on its declared queue or else the batch's.
     *
     * @return void
     */
    public function testChainInABatchKeepsEachLinksDeclaredQueue(): void
    {
        $this->useDatabaseQueue();
        $this->recordProcessedJobs();

        Bus::batch([
            [ContextReadingService::make(new ArrayInput([]))->toJob(), ReportQueueService::make(new ArrayInput([]))->toJob()],
            [ConcernCapturingService::make(new ArrayInput([]))->toJob(), ContextReadingService::make(new ArrayInput([]))->toJob()],
        ])->onQueue('batch')->dispatch();

        $this->workUntilEmpty(queue: 'batch');
        $this->workUntilEmpty(queue: ReportQueueService::QUEUE);

        $expected = [
            ['batch', ConcernCapturingService::class],
            ['batch', ContextReadingService::class],
            ['batch', ContextReadingService::class],
            [ReportQueueService::QUEUE, ReportQueueService::class],
        ];

        sort($this->processed);

        self::assertSame($expected, $this->processed);
    }

    /**
     * Run the job as a worker would after restoring it from the queue.
     *
     * @param  \SineMacula\ApiToolkit\Services\Jobs\ServiceJob  $job
     * @return void
     */
    private function runRestored(ServiceJob $job): void
    {
        $restored = unserialize(serialize($job));

        assert($restored instanceof ServiceJob);

        $restored->handle(new ServiceRunner);
    }

    /**
     * Record the queue and name of every job a worker processes.
     *
     * @return void
     */
    private function recordProcessedJobs(): void
    {
        Queue::after(function (JobProcessed $event): void {
            $this->processed[] = [$event->job->getQueue(), $event->job->resolveName()];
        });
    }

    /**
     * Give the encrypter a key, as an application that encrypts jobs has.
     *
     * @return void
     */
    private function useEncryptionKey(): void
    {
        Config::set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    /**
     * Return the raw payload of the single job stored in the given table.
     *
     * @param  string  $table
     * @return string
     */
    private function storedPayload(string $table): string
    {
        $payload = DB::connection('queue_probe')->table($table)->value('payload');

        assert(is_string($payload));

        return $payload;
    }

    /**
     * Decode the payload of the single queued job.
     *
     * @return array<array-key, mixed>
     */
    private function decodedPayload(): array
    {
        $payload = json_decode($this->storedPayload('jobs'), true, flags: JSON_THROW_ON_ERROR);

        assert(is_array($payload));

        return $payload;
    }

    /**
     * Return the serialised, possibly encrypted, command of the queued job.
     *
     * @return string
     */
    private function storedCommand(): string
    {
        $data = $this->decodedPayload()['data'];

        assert(is_array($data) && is_string($data['command']));

        return $data['command'];
    }

    /**
     * Decrypt and restore the command of the queued job.
     *
     * @return \SineMacula\ApiToolkit\Services\Jobs\ServiceJob
     */
    private function decryptedJob(): ServiceJob
    {
        $serialised = Crypt::decrypt($this->storedCommand());

        assert(is_string($serialised));

        $job = unserialize($serialised);

        assert($job instanceof ServiceJob);

        return $job;
    }

    /**
     * Return how often the failing service ran and each failure hook fired.
     *
     * @return array<string, int>
     */
    private function attemptCounts(): array
    {
        return [
            'runs'           => CountingFailingService::$runs,
            'onFailure'      => CountingFailingService::$failures,
            'ServiceFailed'  => $this->failedEvents,
            'onFinalFailure' => count(CountingFailingService::$finalFailures),
        ];
    }
}
