<?php

declare(strict_types = 1);

namespace Tests\Integration\DeferredWrites;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Jobs\ServiceJob;
use SineMacula\ApiToolkit\Services\ServiceContext;
use Tests\Concerns\UsesDatabaseQueue;
use Tests\Fixtures\Jobs\DeferringJob;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Services\Jobs\DeferringService;
use Tests\Fixtures\Services\StubActor;
use Tests\TestCase;

/**
 * Integration tests that a real queue worker persists the rows each job attempt
 * defers, whether the worker runs once or as a daemon.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePoolFlushSubscriber::class)]
final class QueueWorkerDeferredWriteTest extends TestCase
{
    use UsesDatabaseQueue;

    /**
     * Set up a private database queue.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        DeferringJob::reset();
        DeferringService::reset();

        $this->useDatabaseQueue();
    }

    /**
     * Test that a successful attempt inserts its row and leaves the pool empty.
     *
     * @return void
     */
    public function testSuccessfulAttemptInsertsItsRowAndEmptiesThePool(): void
    {
        Queue::push(new DeferringJob('done'));

        $this->workOnce();

        self::assertSame(['done-1@queue.test'], $this->deferredEmails());
        self::assertCount(1, DeferringJob::$pools);
        self::assertTrue(DeferringJob::$pools[0]->isEmpty());
    }

    /**
     * Provide the ways a worker can run a job to completion.
     *
     * @return iterable<string, array{\Closure(self): void}>
     */
    public static function workerModeProvider(): iterable
    {
        yield 'worker run once per attempt' => [static function (self $test): void {
            $test->workOnce();
            $test->workOnce();
        }];

        yield 'daemon worker' => [static function (self $test): void {
            $test->workUntilEmpty();
        }];
    }

    /**
     * Test that a released attempt and its retry each persist their own row,
     * whichever way the worker runs.
     *
     * @param  \Closure(self): void  $work
     * @return void
     */
    #[DataProvider('workerModeProvider')]
    public function testReleasedAttemptAndItsRetryEachPersistTheirRow(\Closure $work): void
    {
        Queue::push(new DeferringJob('retried', [1]));

        $work($this);

        self::assertSame(['retried-1@queue.test', 'retried-2@queue.test'], $this->deferredEmails());
        self::assertSame(0, DB::connection('queue_probe')->table('jobs')->count());
        self::assertSame(0, DB::connection('queue_probe')->table('failed_jobs')->count());
    }

    /**
     * Test that a final failed attempt persists its row and records the
     * failure.
     *
     * @return void
     */
    public function testFinalFailedAttemptPersistsItsRow(): void
    {
        Queue::push(new DeferringJob('failed', [1]));

        $this->workOnce(1);

        self::assertSame(['failed-1@queue.test'], $this->deferredEmails());
        self::assertSame(1, DB::connection('queue_probe')->table('failed_jobs')->count());
    }

    /**
     * Test that a daemon worker starts each job with an empty pool.
     *
     * @return void
     */
    public function testDaemonWorkerStartsEachJobWithAnEmptyPool(): void
    {
        Queue::push(new DeferringJob('first'));
        Queue::push(new DeferringJob('second'));

        $this->workUntilEmpty(maxJobs: 2);

        self::assertSame([0, 0], DeferringJob::$bufferedAtStart);
        self::assertSame(['first-1@queue.test', 'second-1@queue.test'], $this->deferredEmails());
    }

    /**
     * Test that a re-thrown flush failure at the end of a successful attempt is
     * reported once, without retrying the job or skipping the state reset.
     *
     * @return void
     */
    public function testRethrownFlushFailureIsReportedWithoutRetryingTheJob(): void
    {
        Config::set('api-toolkit.deferred_writes.on_failure', 'throw');
        Config::set('api-toolkit.deferred_writes.rethrow_at_boundary', true);

        User::create(['name' => 'Taken', 'email' => 'taken-1@queue.test']);

        $flushes = new \ArrayObject;

        Event::listen(CacheFlushed::class, static function (CacheFlushed $event) use ($flushes): void {
            $flushes->append($event);
        });

        Exceptions::fake();

        Queue::push(new DeferringJob('taken'));

        $this->workUntilEmpty();

        self::assertCount(1, $flushes);
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(WritePoolFlushException::class);
        self::assertSame(0, DB::connection('queue_probe')->table('jobs')->count());
        self::assertSame(0, DB::connection('queue_probe')->table('failed_jobs')->count());
    }

    /**
     * Test that a queued service deferring inside its transaction persists the
     * row of its failed run and of its retry.
     *
     * @param  \Closure(self): void  $work
     * @return void
     */
    #[DataProvider('workerModeProvider')]
    public function testQueuedServiceDeferringInsideItsTransactionPersistsEveryRun(\Closure $work): void
    {
        Queue::push(new ServiceJob(DeferringService::class, new ArrayInput([]), ServiceContext::for(new StubActor)));

        $work($this);

        self::assertSame(2, DeferringService::$runs);
        self::assertSame(['service-1@queue.test', 'service-2@queue.test'], $this->deferredEmails());
    }

    /**
     * Return the emails of the persisted rows, in order.
     *
     * @return array<int, mixed>
     */
    private function deferredEmails(): array
    {
        return DB::table('users')->orderBy('email')->pluck('email')->all();
    }
}
