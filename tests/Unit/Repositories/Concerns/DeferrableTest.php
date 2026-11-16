<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;
use Tests\Concerns\CreatesDeferredWriteTargets;
use Tests\Fixtures\Models\AuditEntry;
use Tests\Fixtures\Models\ImportRow;
use Tests\Fixtures\Models\LedgerEntry;
use Tests\Fixtures\Models\Reading;
use Tests\Fixtures\Repositories\DeferrableUserRepository;
use Tests\TestCase;

/**
 * Tests for the Deferrable trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(Deferrable::class)]
final class DeferrableTest extends TestCase
{
    use CreatesDeferredWriteTargets;

    /** @var string The timestamp used for deferral-based assertions. */
    private const string TIMESTAMP_DEFERRAL = '2026-03-10 12:00:01';

    /** @var string The timestamp used for explicit-value assertions. */
    private const string TIMESTAMP_EXPLICIT = '2026-03-10 09:00:00';

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

        $this->app->scoped(WritePool::class, fn (): WritePool => new WritePool(500, 10000)); // @phpstan-ignore method.nonObject

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
     * Test that boot invokes the parent boot chain.
     *
     * @return void
     */
    public function testBootInvokesParentBootChain(): void
    {
        $reflection = new \ReflectionClass(ApiRepository::class);

        $attributeSetter = $reflection->getProperty('attributeSetter')->getValue($this->repository);

        self::assertInstanceOf(AttributeSetter::class, $attributeSetter);
    }

    /**
     * Test that defer buffers attributes without executing a database query.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferBuffersAttributesWithoutExecutingDatabaseQuery(): void
    {
        $this->repository->defer(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);

        self::assertSame(0, DB::table('users')->count());
    }

    /**
     * Test that defer captures timestamps at deferral time.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferCapturesTimestampsAtDeferralTime(): void
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
     * Test that defer preserves explicit timestamps.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferPreservesExplicitTimestamps(): void
    {
        $this->repository->defer([
            'name'       => 'Carol',
            'email'      => 'carol@example.com',
            'password'   => 'secret',
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-06-01 00:00:00',
        ]);

        $this->repository->flushWrites();

        $user = DB::table('users')->first();

        self::assertNotNull($user);
        self::assertSame('2025-01-01 00:00:00', $user->created_at);
        self::assertSame('2025-06-01 00:00:00', $user->updated_at);
    }

    /**
     * Test that defer adds created_at and updated_at when missing.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferAddsCreatedAtAndUpdatedAtWhenMissing(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TIMESTAMP_EXPLICIT));

        $this->repository->defer(['name' => 'Dave', 'email' => 'dave@example.com', 'password' => 'secret']);

        $this->repository->flushWrites();

        $user = DB::table('users')->first();

        self::assertNotNull($user);
        self::assertSame(self::TIMESTAMP_EXPLICIT, $user->created_at);
        self::assertSame(self::TIMESTAMP_EXPLICIT, $user->updated_at);
    }

    /**
     * Test that flushWrites persists all deferred records.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testFlushWritesPersistsAllDeferredRecords(): void
    {
        $this->repository->defer(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Frank', 'email' => 'frank@example.com', 'password' => 'secret']);

        $flushResult = $this->repository->flushWrites();

        self::assertSame(2, DB::table('users')->count());
        self::assertInstanceOf(WritePoolFlushResult::class, $flushResult);
        self::assertTrue($flushResult->isSuccessful());
    }

    /**
     * Test that flushWrites clears the pool.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testFlushWritesClearsThePool(): void
    {
        $this->repository->defer(['name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'secret']);

        $flushResult = $this->repository->flushWrites();
        $this->repository->flushWrites();

        self::assertSame(1, DB::table('users')->count());
        self::assertTrue($flushResult->isSuccessful());
    }

    /**
     * Test that defer uses the model table name.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferUsesModelTableName(): void
    {
        $this->repository->defer(['name' => 'Heidi', 'email' => 'heidi@example.com', 'password' => 'secret']);

        $this->repository->flushWrites();

        self::assertSame(1, DB::table('users')->count());
    }

    /**
     * Test that multiple defer calls accumulate in the pool.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testMultipleDeferCallsAccumulateInPool(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repository->defer(['name' => "user_{$i}", 'email' => "user_{$i}@example.com", 'password' => 'secret']);
        }

        self::assertSame(0, DB::table('users')->count());

        $this->repository->flushWrites();

        self::assertSame(5, DB::table('users')->count());
    }

    /**
     * Test that flushWrites returns a WritePoolFlushResult.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testFlushWritesReturnsWritePoolFlushResult(): void
    {
        $this->repository->defer(['name' => 'Iris', 'email' => 'iris@example.com', 'password' => 'secret']);
        $this->repository->defer(['name' => 'Jack', 'email' => 'jack@example.com', 'password' => 'secret']);

        $flushResult = $this->repository->flushWrites();

        self::assertInstanceOf(WritePoolFlushResult::class, $flushResult);
    }

    /**
     * Test that defer propagates a failed auto-flush under THROW.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferPropagatesThrowExceptionOnAutoFlushFailure(): void
    {
        $transactions = $this->transactionTracker();

        $this->app->scoped(WritePool::class, fn (): WritePool => new WritePool(500, 1, FlushStrategy::THROW, transactions: $transactions)); // @phpstan-ignore method.nonObject

        $repository = $this->app->make(DeferrableUserRepository::class); // @phpstan-ignore method.nonObject

        $this->expectException(WritePoolFlushException::class);

        $repository->defer(['name' => 'Kim', 'email' => 'kim@example.com', 'password' => 'secret', 'nonexistent_column' => 'x']);
    }

    /**
     * Test that defer writes the row to the model's connection only.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferWritesToTheModelConnection(): void
    {
        $this->useAuditConnection();

        $repository = $this->deferrableRepository(AuditEntry::class);

        $repository->defer(['action' => 'login']);

        self::assertTrue($repository->flushWrites()->isSuccessful());
        self::assertSame(['login'], DB::connection('audit')->table('audit_entries')->pluck('action')->all());
        self::assertFalse(DB::getSchemaBuilder()->hasTable('audit_entries'));
    }

    /**
     * Test that a default connection changed after deferral does not move the
     * buffered row.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferKeepsTheConnectionResolvedAtDeferral(): void
    {
        $this->useAuditConnection();

        $this->repository->defer(['name' => 'Lena', 'email' => 'lena@example.com', 'password' => 'secret']);

        DB::setDefaultConnection('audit');

        try {
            $flushResult = $this->repository->flushWrites();
        } finally {
            DB::setDefaultConnection('testing');
        }

        self::assertTrue($flushResult->isSuccessful());
        self::assertSame(1, DB::table('users')->count());
    }

    /**
     * Test that a model without timestamps defers no timestamp columns.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferAddsNoTimestampsForAModelWithoutThem(): void
    {
        $repository = $this->deferrableRepository(ImportRow::class);

        $repository->defer(['reference' => 'r-1', 'payload' => 'p']);

        self::assertTrue($repository->flushWrites()->isSuccessful());
        self::assertSame(1, DB::table('import_rows')->count());
    }

    /**
     * Test that defer fills the model's custom timestamp columns and not the
     * default ones.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferFillsCustomTimestampColumns(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TIMESTAMP_DEFERRAL));

        $repository = $this->deferrableRepository(LedgerEntry::class);

        $repository->defer(['label' => 'filled']);
        $repository->defer(['label' => 'given', 'created' => self::TIMESTAMP_EXPLICIT]);

        self::assertTrue($repository->flushWrites()->isSuccessful());
        self::assertSame([
            ['filled', self::TIMESTAMP_DEFERRAL, self::TIMESTAMP_DEFERRAL],
            ['given', self::TIMESTAMP_EXPLICIT, self::TIMESTAMP_DEFERRAL],
        ], DB::table('ledger_entries')->orderBy('id')->get(['label', 'created', 'modified'])->map(
            fn (object $row): array => array_values((array) $row),
        )->all());
    }

    /**
     * Test that defer formats timestamps with the model's date format and skips
     * a timestamp column the model sets to null.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function testDeferUsesTheModelDateFormatAndSkipsANullTimestampColumn(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TIMESTAMP_DEFERRAL));

        $repository = $this->deferrableRepository(Reading::class);

        $repository->defer(['label' => 'gauge']);

        self::assertTrue($repository->flushWrites()->isSuccessful());
        self::assertSame((string) Carbon::parse(self::TIMESTAMP_DEFERRAL)->getTimestamp(), DB::table('readings')->value('updated_at'));
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
