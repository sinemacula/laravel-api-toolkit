<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Jobs;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\PreconditionFailedException;
use SineMacula\ApiToolkit\Exceptions\ServiceFailedException;
use SineMacula\ApiToolkit\Services\Enums\ServiceSource;
use SineMacula\ApiToolkit\Services\Events\ServiceCompleted;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\UsesDatabaseQueue;
use Tests\Fixtures\Exceptions\ServiceExecutionException;
use Tests\Fixtures\Input\StorePayload;
use Tests\Fixtures\Services\Input\BackedEnumInput;
use Tests\Fixtures\Services\Input\SensitiveInput;
use Tests\Fixtures\Services\Jobs\ConcernCapturingService;
use Tests\Fixtures\Services\Jobs\ConfiguredQueueService;
use Tests\Fixtures\Services\Jobs\ContextCapturingConcern;
use Tests\Fixtures\Services\Jobs\ContextReadingService;
use Tests\Fixtures\Services\Jobs\CountingFailingService;
use Tests\Fixtures\Services\Jobs\EncryptedService;
use Tests\Fixtures\Services\Jobs\MissingLockSubjectService;
use Tests\Fixtures\Services\Jobs\RefusingService;
use Tests\Fixtures\Services\Jobs\ReportQueueService;
use Tests\Fixtures\Services\RenamedInputService;
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
    use UsesDatabaseQueue;

    /**
     * Reset the recording fixtures before each test.
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
        MissingLockSubjectService::reset();
        RefusingService::reset();
        app()->instance(ContextCapturingConcern::class, new ContextCapturingConcern);
    }

    /**
     * Test that the job survives a PHP serialise round trip.
     *
     * @return void
     */
    public function testJobSerialisesAndRestores(): void
    {
        $context = ServiceContext::for(new StubActor, ServiceSource::HTTP, [], 'corr-123');
        $job     = ConcernCapturingService::make(new ArrayInput(['key' => 'value']))->withContext($context)->toJob();

        $restored = unserialize(serialize($job));
        assert($restored instanceof ServiceJob);

        self::assertSame(ConcernCapturingService::class, $restored->service);
        self::assertSame(['key' => 'value'], $restored->input->toArray());
        self::assertSame('corr-123', $restored->context->correlationId);
        self::assertSame('stub-id', $restored->context->actor->actorIdentifier());
    }

    /**
     * Test that every queue setting the service declares lands on the job.
     *
     * @return void
     */
    public function testForCopiesTheQueueSettingsTheServiceDeclares(): void
    {
        $job = $this->jobFor(new ConfiguredQueueService(new ArrayInput([])));

        self::assertSame('redis', $job->connection);
        self::assertSame('reports', $job->queue);
        self::assertSame(30, $job->delay);
        self::assertSame(3, $job->tries);
        self::assertSame(120, $job->timeout);
        self::assertSame([5, 10], $job->backoff);
        self::assertSame(2, $job->maxExceptions);
        self::assertTrue($job->failOnTimeout);
        self::assertTrue($job->countCrashesAsExceptions);
    }

    /**
     * Test that a setting the service does not declare keeps Laravel's default,
     * so the worker's own options still apply.
     *
     * @return void
     */
    public function testForLeavesUndeclaredSettingsAtTheirDefaults(): void
    {
        $job = $this->jobFor(new ContextReadingService(new ArrayInput([])));

        self::assertNull($job->connection);
        self::assertNull($job->queue);
        self::assertNull($job->delay);
        self::assertNull($job->tries);
        self::assertNull($job->timeout);
        self::assertNull($job->backoff);
        self::assertNull($job->maxExceptions);
        self::assertFalse($job->failOnTimeout);
        self::assertFalse($job->countCrashesAsExceptions);
        self::assertNull($job->afterCommit);
        self::assertFalse($job->shouldBeEncrypted);
    }

    /**
     * Test that an undeclared queue and connection follow the service's route.
     *
     * @return void
     */
    public function testForRoutesAnUndeclaredQueueByTheServiceClass(): void
    {
        Queue::route(ContextReadingService::class, 'routed', 'routed-connection');

        $job = $this->jobFor(new ContextReadingService(new ArrayInput([])));

        self::assertSame('routed', $job->queue);
        self::assertSame('routed-connection', $job->connection);
    }

    /**
     * Test that a declared queue beats the service's route.
     *
     * @return void
     */
    public function testForPrefersTheDeclaredQueueOverTheRoute(): void
    {
        Queue::route(ReportQueueService::class, 'routed', 'routed-connection');

        $job = $this->jobFor(new ReportQueueService(new ArrayInput([])));

        self::assertSame(ReportQueueService::QUEUE, $job->queue);
        self::assertSame('routed-connection', $job->connection);
    }

    /**
     * Test that a declared connection beats the service's route.
     *
     * @return void
     */
    public function testForPrefersTheDeclaredConnectionOverTheRoute(): void
    {
        Queue::route(ConfiguredQueueService::class, 'routed', 'routed-connection');

        self::assertSame('redis', $this->jobFor(new ConfiguredQueueService(new ArrayInput([])))->connection);
    }

    /**
     * Test that a ShouldQueueAfterCommit service is queued after commit.
     *
     * @return void
     */
    public function testForQueuesAnAfterCommitServiceAfterCommit(): void
    {
        $service = new class (new ArrayInput([])) extends Service implements ShouldQueueAfterCommit {
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
        };

        self::assertTrue($this->jobFor($service)->afterCommit);
    }

    /**
     * Test that a ShouldBeEncrypted service is encrypted.
     *
     * @return void
     */
    public function testForEncryptsAShouldBeEncryptedService(): void
    {
        self::assertTrue($this->jobFor(new EncryptedService(new ArrayInput([])))->shouldBeEncrypted);
    }

    /**
     * Test that a payload promoting a sensitive parameter is encrypted.
     *
     * @return void
     */
    public function testForEncryptsAPayloadWithASensitiveParameter(): void
    {
        $service = new ContextReadingService(new SensitiveInput('reader', 'hidden'));

        self::assertTrue($this->jobFor($service)->shouldBeEncrypted);
    }

    /**
     * Test that a payload without a sensitive parameter is not encrypted.
     *
     * @return void
     */
    public function testForLeavesAPlainPayloadUnencrypted(): void
    {
        $service = new ContextReadingService(new BackedEnumInput);

        self::assertFalse($this->jobFor($service)->shouldBeEncrypted);
    }

    /**
     * Test that the job is named after the service it runs.
     *
     * @return void
     */
    public function testDisplayNameIsTheServiceClass(): void
    {
        $job = $this->jobFor(new ReportQueueService(new ArrayInput([])));

        self::assertSame(ReportQueueService::class, $job->displayName());
    }

    /**
     * Test that handle() rebuilds the service and runs it via the runner.
     *
     * @return void
     */
    public function testHandleRebuildsAndRunsViaRunner(): void
    {
        $this->jobFor(new ConcernCapturingService(new ArrayInput([])))->handle(new ServiceRunner);

        self::assertNotNull(ContextCapturingConcern::$captured);
        self::assertSame(ServiceSource::QUEUE, ContextCapturingConcern::$captured->source);
    }

    /**
     * Test that handle() runs a service that names its input differently on the
     * dispatched input, not one built from the worker's request.
     *
     * @return void
     */
    public function testHandleRunsAServiceWithARenamedInputParameterOnTheDispatchedInput(): void
    {
        Event::fake([ServiceCompleted::class]);

        $input = new StorePayload(title: 'dispatched', quantity: 2);
        $job   = RenamedInputService::make($input)->withContext(ServiceContext::for(new StubActor))->toJob();

        $job->handle(new ServiceRunner);

        Event::assertDispatched(ServiceCompleted::class, static fn (ServiceCompleted $event): bool => $event->inputSummary === $input->toArray());
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

        $this->jobFor(new ConcernCapturingService(new ArrayInput([])))->handle(new ServiceRunner);

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
        $metadata = ['trace' => 'queued-trace'];
        $context  = ServiceContext::for(new StubActor, ServiceSource::HTTP, $metadata, 'queued-corr');

        ContextReadingService::make(new ArrayInput([]))->withContext($context)->toJob()->handle(new ServiceRunner);

        $seen = ContextReadingService::$seen;

        self::assertNotNull($seen);
        self::assertSame(ServiceSource::QUEUE, $seen->source);
        self::assertSame('stub-id', $seen->actor->actorIdentifier());
        self::assertSame('queued-corr', $seen->correlationId);
        self::assertSame($metadata, $seen->metadata);
        self::assertNull($seen->batchId);
    }

    /**
     * Test that a batched run reads its batch id from the context.
     *
     * @return void
     */
    public function testBatchedRunReadsItsBatchId(): void
    {
        $this->jobFor(new ContextReadingService(new ArrayInput([])))->withBatchId('batch-id')->handle(new ServiceRunner);

        self::assertSame('batch-id', ContextReadingService::$seen?->batchId);
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
        $job = $this->jobFor(new CountingFailingService(new ArrayInput([])));

        try {
            $job->handle(new ServiceRunner);
            self::fail('A failed run must fail the job.');
        } catch (ServiceExecutionException $exception) {
            self::assertSame(CountingFailingService::MESSAGE, $exception->getMessage());
            self::assertSame(1, CountingFailingService::$runs);
        }
    }

    /**
     * Test that failed() relays the failure to onFinalFailure() with the
     * worker's context.
     *
     * @return void
     */
    public function testFailedRelaysTheFailureWithTheWorkerContext(): void
    {
        $exception = new \RuntimeException('Gave up.');
        $context   = ServiceContext::for(new StubActor, ServiceSource::HTTP, [], 'final-corr');

        RefusingService::make(new ArrayInput([]))->withContext($context)->toJob()->withBatchId('batch-id')->failed($exception);

        $seen = RefusingService::$finalContext;

        self::assertSame([$exception], RefusingService::$finalFailures);
        self::assertNotNull($seen);
        self::assertSame(ServiceSource::QUEUE, $seen->source);
        self::assertSame('final-corr', $seen->correlationId);
        self::assertSame('batch-id', $seen->batchId);
        self::assertSame(0, RefusingService::$attempts);
    }

    /**
     * Test that failed() without an exception relays a ServiceFailedException.
     *
     * @return void
     */
    public function testFailedWithoutAnExceptionRelaysAServiceFailedException(): void
    {
        $this->jobFor(new RefusingService(new ArrayInput([])))->failed(null);

        self::assertCount(1, RefusingService::$finalFailures);
        self::assertInstanceOf(ServiceFailedException::class, RefusingService::$finalFailures[0]);
    }

    /**
     * Test that onFinalFailure() runs although lockId() cannot be resolved.
     *
     * @return void
     */
    public function testFinalFailureRunsWhenTheLockIdCannotBeResolved(): void
    {
        $job = $this->jobFor(new MissingLockSubjectService(new ArrayInput([])));

        try {
            Queue::connection('sync')->push($job);
            self::fail('A failed run must fail the job.');
        } catch (ModelNotFoundException $exception) {
            self::assertSame([$exception], MissingLockSubjectService::$finalFailures);
        }
    }

    /**
     * Provide the refusals a worker fails at once.
     *
     * @return iterable<string, array{\Closure(): \Throwable}>
     */
    public static function refusalProvider(): iterable
    {
        yield 'authorization' => [static fn (): \Throwable => new AuthorizationException('Denied.')];
        yield 'validation' => [static fn (): \Throwable => ValidationException::withMessages(['name' => 'Invalid.'])];
        yield 'precondition failed' => [static fn (): \Throwable => new PreconditionFailedException];
    }

    /**
     * Test that a refusal on the worker fails the job on its first attempt
     * rather than retrying it.
     *
     * @param  \Closure(): \Throwable  $refusal
     * @return void
     */
    #[DataProvider('refusalProvider')]
    public function testRefusalOnTheWorkerFailsTheJobWithoutRetrying(\Closure $refusal): void
    {
        $this->useDatabaseQueue();

        RefusingService::$refusal = $refusal();

        Queue::push($this->jobFor(new RefusingService(new ArrayInput([]))));

        $this->workOnce(tries: 2);

        self::assertSame(1, RefusingService::$attempts);
        self::assertSame(1, RefusingService::$failures);
        self::assertSame([RefusingService::$refusal], RefusingService::$finalFailures);
        self::assertSame(0, DB::connection('queue_probe')->table('jobs')->count());
        self::assertSame(1, DB::connection('queue_probe')->table('failed_jobs')->count());
    }

    /**
     * Test that a refusal cancels its batch and the jobs after it are skipped.
     *
     * @return void
     */
    public function testRefusalCancelsTheBatchAndSkipsTheRest(): void
    {
        $this->useDatabaseQueue();

        RefusingService::$refusal = new AuthorizationException('Denied.');

        $batch = Bus::batch([
            RefusingService::make(new ArrayInput([]))->toJob(),
            ContextReadingService::make(new ArrayInput([]))->toJob(),
        ])->dispatch();

        $this->workUntilEmpty();

        self::assertSame(1, RefusingService::$attempts);
        self::assertNull(ContextReadingService::$seen);
        self::assertTrue(Bus::findBatch($batch->id)?->cancelled());
        self::assertSame(0, DB::connection('queue_probe')->table('jobs')->count());
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

        try {
            Queue::connection('sync')->push($this->jobFor(new CountingFailingService(new ArrayInput([]))));
            self::fail('A failed run must fail the job.');
        } catch (ServiceExecutionException $exception) {
            self::assertSame([$exception], $failures);
            self::assertSame([$exception], CountingFailingService::$finalFailures);
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

        Queue::connection('sync')->push($this->jobFor(new ConcernCapturingService(new ArrayInput([]))));

        self::assertSame(0, $failures);
        self::assertNotNull(ContextCapturingConcern::$captured);
    }

    /**
     * Build the job for a service run by a stub actor.
     *
     * @param  \SineMacula\ApiToolkit\Services\Service<covariant \SineMacula\ApiToolkit\Services\Contracts\ServiceInput, covariant mixed>  $service
     * @return \SineMacula\ApiToolkit\Services\Jobs\ServiceJob
     */
    private function jobFor(Service $service): ServiceJob
    {
        return $service->by(new StubActor)->toJob();
    }
}
