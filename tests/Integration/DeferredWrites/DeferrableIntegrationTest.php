<?php

declare(strict_types = 1);

namespace Tests\Integration\DeferredWrites;

use Carbon\Carbon;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Events\WritePoolFlushFailed;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Concerns\CreatesDeferredWriteTargets;
use Tests\Fixtures\Models\AuditEntry;
use Tests\Fixtures\Models\ImportRow;
use Tests\Fixtures\Models\LedgerEntry;
use Tests\Fixtures\Repositories\DeferrableUserRepository;
use Tests\TestCase;

/**
 * Integration tests for the deferred writes feature.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(Deferrable::class)]
final class DeferrableIntegrationTest extends TestCase
{
    use CreatesDeferredWriteTargets;

    /** @var string The timestamp used to assert deferred dispatch timing. */
    private const string TIMESTAMP_DEFERRAL = '2026-03-10 12:00:01';

    /** @var \Tests\Fixtures\Repositories\DeferrableUserRepository The repository instance under test. */
    private DeferrableUserRepository $repository;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->scoped(WritePool::class, fn (): WritePool => new WritePool(3, 5, transactions: $this->transactionTracker(...))); // @phpstan-ignore method.nonObject

        $this->repository = $this->app->make(DeferrableUserRepository::class); // @phpstan-ignore method.nonObject
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Test that deferred inserts are not persisted until flush.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferredInsertsAreNotPersistedUntilFlush(): void
    {
        $this->repository->defer(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);

        self::assertSame(0, DB::table('users')->count());

        $this->repository->flushWrites();

        self::assertSame(1, DB::table('users')->count());
    }

    /**
     * Test that deferred inserts are flushed as bulk inserts.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferredInsertsAreFlushedAsBulkInserts(): void
    {
        $this->app->scoped(WritePool::class, fn (): WritePool => new WritePool(3, 10000)); // @phpstan-ignore method.nonObject

        $this->repository = $this->app->make(DeferrableUserRepository::class); // @phpstan-ignore method.nonObject

        DB::enableQueryLog();

        for ($i = 0; $i < 6; $i++) {
            $this->repository->defer(['name' => "user_{$i}", 'email' => "user_{$i}@example.com", 'password' => 'secret']);
        }

        $this->repository->flushWrites();

        $queries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_starts_with($query['query'], 'insert'),
        );

        DB::disableQueryLog();

        self::assertCount(2, $queries);
        self::assertSame(6, DB::table('users')->count());
    }

    /**
     * Test that timestamps are captured at deferral time.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testTimestampsAreCapturedAtDeferralTime(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TIMESTAMP_DEFERRAL));

        $this->repository->defer(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);

        Carbon::setTestNow(Carbon::parse('2026-03-10 12:00:05'));

        $this->repository->flushWrites();

        $user = DB::table('users')->first();

        self::assertNotNull($user);
        self::assertSame(self::TIMESTAMP_DEFERRAL, $user->created_at);
        self::assertSame(self::TIMESTAMP_DEFERRAL, $user->updated_at);
    }

    /**
     * Test that manual flush clears the pool and allows fresh accumulation.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testManualFlushClearsPoolAndAllowsFreshAccumulation(): void
    {
        $this->repository->defer(['name' => 'Carol', 'email' => 'carol@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Dave', 'email' => 'dave@example.com', 'password' => 'secret']);

        $this->repository->flushWrites();

        self::assertSame(2, DB::table('users')->count());

        $this->repository->defer(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'secret']);

        $this->repository->flushWrites();

        self::assertSame(3, DB::table('users')->count());
    }

    /**
     * Test that pool limit triggers auto-flush.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testPoolLimitTriggersAutoFlush(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repository->defer(['name' => "user_{$i}", 'email' => "user_{$i}@example.com", 'password' => 'secret']);
        }

        self::assertSame(5, DB::table('users')->count());
    }

    /**
     * Test that RequestHandled event triggers flush.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testRequestHandledEventTriggersFlush(): void
    {
        $this->repository->defer(['name' => 'Frank', 'email' => 'frank@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'secret']);

        Event::dispatch(new RequestHandled(new Request, new Response));

        self::assertSame(2, DB::table('users')->count());
    }

    /**
     * Test that CommandFinished event triggers flush.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testCommandFinishedEventTriggersFlush(): void
    {
        $this->repository->defer(['name' => 'Heidi', 'email' => 'heidi@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Ivan', 'email' => 'ivan@example.com', 'password' => 'secret']);

        Event::dispatch(new CommandFinished('test:command', new ArrayInput([]), new NullOutput, 0));

        self::assertSame(2, DB::table('users')->count());
    }

    /**
     * Test that JobAttempted event triggers flush.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testJobAttemptedEventTriggersFlush(): void
    {
        $this->repository->defer(['name' => 'Judy', 'email' => 'judy@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Karl', 'email' => 'karl@example.com', 'password' => 'secret']);

        $job = \Mockery::mock(Job::class);

        Event::dispatch(new JobAttempted('sync', $job, new \RuntimeException('test')));

        self::assertSame(2, DB::table('users')->count());
    }

    /**
     * Test that WritePool is registered as a scoped singleton.
     *
     * @return void
     */
    public function testWritePoolIsRegisteredAsScopedSingleton(): void
    {
        $poolA = $this->app->make(WritePool::class); // @phpstan-ignore method.nonObject
        $poolB = $this->app->make(WritePool::class); // @phpstan-ignore method.nonObject

        self::assertSame($poolA, $poolB);
    }

    /**
     * Test that deferred writes config is available.
     *
     * @return void
     */
    public function testDeferredWritesConfigIsAvailable(): void
    {
        self::assertNotNull(Config::get('api-toolkit.deferred_writes.chunk_size'));
        self::assertNotNull(Config::get('api-toolkit.deferred_writes.pool_limit'));
    }

    /**
     * Test that existing repository operations are unaffected.
     *
     * @return void
     */
    public function testExistingRepositoryOperationsAreUnaffected(): void
    {
        $this->repository->create([ // @phpstan-ignore staticMethod.dynamicCall
            'name'     => 'Nina',
            'email'    => 'nina@example.com',
            'password' => 'secret',
        ]);

        self::assertSame(1, DB::table('users')->count());
    }

    /**
     * Test that flushWrites returns a result with success details.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testFlushWritesReturnsResultWithSuccessDetails(): void
    {
        $this->repository->defer(['name' => 'Oscar', 'email' => 'oscar@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Penny', 'email' => 'penny@example.com', 'password' => 'secret']);

        $flushResult = $this->repository->flushWrites();

        self::assertTrue($flushResult->isSuccessful());
        self::assertGreaterThan(0, $flushResult->successCount());
    }

    /**
     * Test that RequestHandled dispatches WritePoolFlushFailed on failure.
     *
     * @return void
     */
    public function testRequestHandledEventDispatchesFailedEventOnFlushFailure(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);

        $this->app->scoped(WritePool::class, fn (): WritePool => $pool); // @phpstan-ignore method.nonObject

        Event::fake([WritePoolFlushFailed::class]);

        Exceptions::fake();

        Event::dispatch(new RequestHandled(new Request, new Response));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(WritePoolFlushException::class);

        Event::assertDispatched(WritePoolFlushFailed::class, fn (WritePoolFlushFailed $event): bool => $event->flushResult->failureCount() === 1);
    }

    /**
     * Test that the default strategy retains failed records after a flush.
     *
     * @return void
     */
    public function testBoundaryFailureRetainsRecordsUnderDefaultStrategy(): void
    {
        $pool = new WritePool(500, 10000);
        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);

        $this->app->scoped(WritePool::class, fn (): WritePool => $pool); // @phpstan-ignore method.nonObject

        Event::fake([WritePoolFlushFailed::class]);

        Exceptions::fake();

        Event::dispatch(new RequestHandled(new Request, new Response));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(WritePoolFlushException::class);

        self::assertSame(1, $pool->count());
        self::assertFalse($pool->isEmpty());
    }

    /**
     * Test that a transactional boundary flush rolls back a failing table.
     *
     * @return void
     */
    public function testTransactionalBoundaryFlushRollsBackTableAndDispatchesEvent(): void
    {
        $pool = new WritePool(1, 10000, FlushStrategy::COLLECT, transactional: true);
        $pool->add('testing', 'users', ['name' => 'Tess', 'email' => 'tess@example.com', 'password' => 'secret']);
        $pool->add('testing', 'users', ['name' => 'Tess', 'email' => 'tess@example.com', 'password' => 'secret']);

        $this->app->scoped(WritePool::class, fn (): WritePool => $pool); // @phpstan-ignore method.nonObject

        Event::fake([WritePoolFlushFailed::class]);

        Exceptions::fake();

        Event::dispatch(new RequestHandled(new Request, new Response));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(WritePoolFlushException::class);

        self::assertSame(0, DB::table('users')->count());
        self::assertSame(2, $pool->count());

        Event::assertDispatched(WritePoolFlushFailed::class);
    }

    /**
     * Test that WritePoolFlushResult is propagated through Deferrable.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testWritePoolFlushResultPropagatedThroughDeferrable(): void
    {
        $this->repository->defer(['name' => 'Quinn', 'email' => 'quinn@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Rita', 'email' => 'rita@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'secret']);

        $flushResult = $this->repository->flushWrites();

        self::assertInstanceOf(WritePoolFlushResult::class, $flushResult);
        self::assertTrue($flushResult->isSuccessful());
        self::assertSame(1, $flushResult->successCount());
        self::assertSame(0, $flushResult->failureCount());
    }

    /**
     * Test that the boundary flush writes each model's rows to its own
     * connection.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testBoundaryFlushWritesEachModelToItsConnection(): void
    {
        $this->useAuditConnection();

        $this->deferrableRepository(AuditEntry::class)->defer(['action' => 'login']);
        $this->repository->defer(['name' => 'Uma', 'email' => 'uma@example.com', 'password' => 'secret']);

        Event::dispatch(new RequestHandled(new Request, new Response));

        self::assertSame(1, DB::connection('audit')->table('audit_entries')->count());
        self::assertSame(1, DB::table('users')->count());
    }

    /**
     * Test that the boundary flush persists models whose timestamps differ from
     * the defaults.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testBoundaryFlushPersistsModelsWithOwnTimestampSettings(): void
    {
        $this->deferrableRepository(ImportRow::class)->defer(['reference' => 'r-1', 'payload' => 'p']);
        $this->deferrableRepository(LedgerEntry::class)->defer(['label' => 'entry']);

        Event::dispatch(new RequestHandled(new Request, new Response));

        self::assertSame(1, DB::table('import_rows')->count());
        self::assertNotNull(DB::table('ledger_entries')->value('created'));
    }

    /**
     * Test that a full pool inside a transaction waits for the boundary flush.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testFullPoolInsideATransactionWaitsForTheBoundaryFlush(): void
    {
        DB::transaction(function (): void {
            for ($i = 0; $i < 5; $i++) {
                $this->repository->defer(['name' => "user_{$i}", 'email' => "user_{$i}@example.com", 'password' => 'secret']);
            }
        });

        self::assertSame(0, DB::table('users')->count());

        Event::dispatch(new RequestHandled(new Request, new Response));

        self::assertSame(5, DB::table('users')->count());
    }

    /**
     * Define the database migrations.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createTimestampFixtureTables();
    }
}
