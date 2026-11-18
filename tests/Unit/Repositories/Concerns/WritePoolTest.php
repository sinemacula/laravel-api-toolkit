<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;
use SineMacula\Repositories\Concerns\CacheSizeGuard;
use SineMacula\Repositories\Concerns\CacheStore;
use SineMacula\Repositories\Concerns\CacheStoreOptions;
use Tests\Concerns\CreatesDeferredWriteTargets;
use Tests\TestCase;

/**
 * Tests for the WritePool collaborator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePool::class)]
final class WritePoolTest extends TestCase
{
    use CreatesDeferredWriteTargets;

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\WritePool The write pool instance under test. */
    private WritePool $pool;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->pool = new WritePool(chunkSize: 500, poolLimit: 10000);
    }

    /**
     * Test that add buffers attributes for a given table.
     *
     * @return void
     */
    public function testAddBuffersAttributesForGivenTable(): void
    {
        $this->pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        self::assertSame(1, $this->pool->count());
        self::assertFalse($this->pool->isEmpty());
    }

    /**
     * Test that add buffers attributes for multiple tables.
     *
     * @return void
     */
    public function testAddBuffersAttributesForMultipleTables(): void
    {
        $this->pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->add('testing', 'test_other', ['label' => 'baz']);

        self::assertSame(2, $this->pool->count());
    }

    /**
     * Test that count returns zero when the buffer is empty.
     *
     * @return void
     */
    public function testCountReturnsZeroWhenEmpty(): void
    {
        self::assertSame(0, $this->pool->count());
    }

    /**
     * Test that isEmpty returns true when no records are buffered.
     *
     * @return void
     */
    public function testIsEmptyReturnsTrueWhenNoRecordsBuffered(): void
    {
        self::assertTrue($this->pool->isEmpty());
    }

    /**
     * Test that isEmpty returns false after add.
     *
     * @return void
     */
    public function testIsEmptyReturnsFalseAfterAdd(): void
    {
        $this->pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        self::assertFalse($this->pool->isEmpty());
    }

    /**
     * Test that flush inserts records into the database.
     *
     * @return void
     */
    public function testFlushInsertsRecordsIntoDatabase(): void
    {
        $this->pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->add('testing', 'test_records', ['name' => 'baz', 'value' => 'qux']);

        $flushResult = $this->pool->flush();

        self::assertTrue($flushResult->isSuccessful());
        self::assertSame(2, DB::table('test_records')->count());
    }

    /**
     * Test that flush groups records by table.
     *
     * @return void
     */
    public function testFlushGroupsRecordsByTable(): void
    {
        $this->pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->add('testing', 'test_other', ['label' => 'baz']);

        $flushResult = $this->pool->flush();

        self::assertTrue($flushResult->isSuccessful());
        self::assertSame(1, DB::table('test_records')->count());
        self::assertSame(1, DB::table('test_other')->count());
    }

    /**
     * Test that flush clears the buffer after execution.
     *
     * @return void
     */
    public function testFlushClearsBufferAfterExecution(): void
    {
        $this->pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $this->pool->flush();

        self::assertTrue($flushResult->isSuccessful());
        self::assertSame(0, $this->pool->count());
        self::assertTrue($this->pool->isEmpty());
    }

    /**
     * Test that flush is a no-op when the buffer is empty.
     *
     * @return void
     */
    public function testFlushIsNoOpWhenBufferIsEmpty(): void
    {
        $flushResult = $this->pool->flush();

        self::assertTrue($flushResult->isSuccessful());
        self::assertSame(0, $flushResult->totalCount());
        self::assertSame(0, $this->pool->count());
    }

    /**
     * Test that flush chunks records by configured chunk size.
     *
     * @return void
     */
    public function testFlushChunksRecordsByConfiguredChunkSize(): void
    {
        $pool = new WritePool(chunkSize: 2, poolLimit: 10000);

        DB::enableQueryLog();

        for ($i = 0; $i < 5; $i++) {
            $pool->add('testing', 'test_records', ['name' => "name_{$i}", 'value' => "value_{$i}"]);
        }

        $flushResult = $pool->flush();

        $queries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_starts_with($query['query'], 'insert'),
        );

        DB::disableQueryLog();

        self::assertSame(3, $flushResult->successCount());
        self::assertCount(3, $queries);
        self::assertSame(5, DB::table('test_records')->count());
    }

    /**
     * Test that a chunk size below one flushes one record per insert.
     *
     * @return void
     */
    public function testFlushChunksOneRecordPerInsertWhenTheChunkSizeIsBelowOne(): void
    {
        $pool = new WritePool(chunkSize: 0, poolLimit: 10000);

        $pool->add('testing', 'test_records', ['name' => 'name_0', 'value' => 'value_0']);
        $pool->add('testing', 'test_records', ['name' => 'name_1', 'value' => 'value_1']);

        self::assertSame(2, $pool->flush()->successCount());
        self::assertSame(2, DB::table('test_records')->count());
    }

    /**
     * Test that the default strategy continues past a failure without logging.
     *
     * @return void
     */
    public function testDefaultStrategyContinuesOnChunkFailureWithoutLogging(): void
    {
        $pool = new WritePool(chunkSize: 2, poolLimit: 10000);

        Log::shouldReceive('warning')->never();

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $pool->flush();

        self::assertFalse($flushResult->isSuccessful());
        self::assertSame(1, $flushResult->failureCount());
        self::assertSame(1, DB::table('test_records')->count());
    }

    /**
     * Test that the default strategy retains failed records in the buffer.
     *
     * @return void
     */
    public function testDefaultStrategyRetainsBufferAfterPartialFailure(): void
    {
        Log::shouldReceive('warning')->never();

        $this->pool->add('testing', 'nonexistent_table', ['col' => 'val']);

        $this->pool->flush();

        self::assertSame(1, $this->pool->count());
        self::assertFalse($this->pool->isEmpty());
    }

    /**
     * Test that the default strategy is collect, with zero dropped records.
     *
     * @return void
     */
    public function testDefaultStrategyIsCollectAndDropsNoRecords(): void
    {
        Log::shouldReceive('warning')->never();

        $this->pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $this->pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $this->pool->flush();

        self::assertFalse($flushResult->isSuccessful());
        self::assertSame(0, $flushResult->droppedRecordCount());
        self::assertSame(1, $flushResult->retainedRecordCount());
        self::assertSame(1, $this->pool->count());
        self::assertInstanceOf(QueryException::class, $flushResult->firstException());
        self::assertSame($flushResult->failures()['testing']['nonexistent_table'][0]['exception'], $flushResult->firstException()->getMessage());
    }

    /**
     * Test that add triggers auto-flush when pool limit is reached.
     *
     * @return void
     */
    public function testAddTriggersAutoFlushWhenPoolLimitReached(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 3, transactions: $this->transactionTracker(...));

        $pool->add('testing', 'test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('testing', 'test_records', ['name' => 'b', 'value' => '2']);
        $pool->add('testing', 'test_records', ['name' => 'c', 'value' => '3']);

        self::assertSame(3, DB::table('test_records')->count());
        self::assertSame(0, $pool->count());
    }

    /**
     * Test that add triggers auto-flush when pool limit is exceeded.
     *
     * @return void
     */
    public function testAddTriggersAutoFlushWhenPoolLimitExceeded(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, transactions: $this->transactionTracker(...));

        $pool->add('testing', 'test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('testing', 'test_records', ['name' => 'b', 'value' => '2']);

        self::assertSame(2, DB::table('test_records')->count());
        self::assertSame(0, $pool->count());
    }

    /**
     * Test that subsequent adds after auto-flush accumulate in a fresh buffer.
     *
     * @return void
     */
    public function testSubsequentAddsAfterAutoFlushAccumulateInFreshBuffer(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, transactions: $this->transactionTracker(...));

        $pool->add('testing', 'test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('testing', 'test_records', ['name' => 'b', 'value' => '2']);

        self::assertSame(0, $pool->count());

        $pool->add('testing', 'test_records', ['name' => 'c', 'value' => '3']);

        self::assertSame(1, $pool->count());
        self::assertSame(2, DB::table('test_records')->count());
    }

    /**
     * Test that a LOG flush returns failure details keyed by the failing table.
     *
     * @return void
     */
    public function testFlushWithLogStrategyReturnsResultWithFailureDetails(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->once()->withAnyArgs();

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $pool->flush();

        self::assertFalse($flushResult->isSuccessful());
        self::assertSame(1, $flushResult->failureCount());
        self::assertSame(1, $flushResult->successCount());
        self::assertArrayHasKey('nonexistent_table', $flushResult->failures()['testing']);
        self::assertSame([['col' => 'val']], $flushResult->failures()['testing']['nonexistent_table'][0]['records']);
        self::assertNotEmpty($flushResult->failures()['testing']['nonexistent_table'][0]['exception']);
        self::assertSame(QueryException::class, $flushResult->failures()['testing']['nonexistent_table'][0]['exception_class']);
    }

    /**
     * Test that a LOG chunk failure logs a warning naming the failing table.
     *
     * @return void
     */
    public function testLogStrategyFailureLogsTableAndErrorContext(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'WritePool flush failed for table [nonexistent_table] on connection [testing]',
                \Mockery::on(fn (array $context): bool => array_keys($context) === ['connection', 'table', 'chunk_size', 'error', 'exception']
                    && $context['connection']                                  === 'testing'
                    && $context['table']                                       === 'nonexistent_table'
                    && $context['chunk_size']                                  === 1
                    && $context['exception'] instanceof QueryException
                    && $context['error'] === $context['exception']->getMessage()),
            );

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);

        $pool->flush();
    }

    /**
     * Test that flush with THROW strategy throws on the first chunk failure.
     *
     * @return void
     */
    public function testFlushWithThrowStrategyThrowsOnFirstFailure(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $this->expectException(WritePoolFlushException::class);

        $pool->flush();
    }

    /**
     * Test that a THROW flush keeps failed and unprocessed records buffered.
     *
     * @return void
     */
    public function testFlushWithThrowStrategyPreservesFailedAndUnprocessedRecords(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        try {
            $pool->flush();
        } catch (WritePoolFlushException) {
            // Buffer state is inspected below
        }

        self::assertFalse($pool->isEmpty());
        self::assertSame(2, $pool->count());
    }

    /**
     * Test that a THROW flush removes inserted records from the buffer.
     *
     * @return void
     */
    public function testFlushWithThrowStrategyRemovesSuccessfullyInsertedRecords(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('testing', 'test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('testing', 'test_records', ['name' => 'b', 'value' => '2']);
        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_other', ['label' => 'baz']);

        try {
            $pool->flush();
        } catch (WritePoolFlushException) {
            // Buffer state is inspected below
        }

        self::assertSame(2, DB::table('test_records')->count());
        self::assertSame(2, $pool->count());
    }

    /**
     * Test that the THROW exception carries a partial result with the counts.
     *
     * @return void
     */
    public function testFlushWithThrowStrategyExceptionContainsPartialResult(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('testing', 'test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);

        try {
            $pool->flush();
            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException $exception) {

            $partialResult = $exception->flushResult();

            self::assertSame(1, $partialResult->successCount());
            self::assertSame(1, $partialResult->failureCount());
        }
    }

    /**
     * Test that a COLLECT flush continues processing after a chunk failure.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyContinuesAfterFailure(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        self::assertSame(1, DB::table('test_records')->count());
    }

    /**
     * Test that a COLLECT flush retains failed records in the buffer.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyRetainsFailedRecordsInBuffer(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        self::assertSame(1, $pool->count());
        self::assertFalse($pool->isEmpty());
    }

    /**
     * Test that a COLLECT flush drops successful records and keeps failed ones.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyRemovesSuccessfulRecordsFromBuffer(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        self::assertSame(1, DB::table('test_records')->count());
        self::assertSame(1, $pool->count());
    }

    /**
     * Test that a COLLECT flush reports failures for every failing table.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyReturnsResultWithAllFailures(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'another_missing_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $pool->flush();

        self::assertSame(2, $flushResult->failureCount());
        self::assertSame(1, $flushResult->successCount());
        self::assertArrayHasKey('nonexistent_table', $flushResult->failures()['testing']);
        self::assertArrayHasKey('another_missing_table', $flushResult->failures()['testing']);
    }

    /**
     * Test that a per-call flush strategy overrides the instance default.
     *
     * @return void
     */
    public function testFlushStrategyOverridePerCall(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('warning')->never();

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush(FlushStrategy::COLLECT);

        self::assertSame(1, $pool->count());
        self::assertFalse($pool->isEmpty());
    }

    /**
     * Test that auto-flush uses LOG when configured with LOG.
     *
     * @return void
     */
    public function testAutoFlushUsesLogStrategyWhenConfiguredAsLog(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, strategy: FlushStrategy::LOG, transactions: $this->transactionTracker(...));

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->once()->withAnyArgs();

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        self::assertSame(0, $pool->count());
    }

    /**
     * Test that a memory-pressure auto-flush under THROW throws out of add().
     *
     * @return void
     */
    public function testAutoFlushThrowsWhenConfiguredAsThrow(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, strategy: FlushStrategy::THROW, transactions: $this->transactionTracker(...));

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);

        $this->expectException(WritePoolFlushException::class);

        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
    }

    /**
     * Test that auto-flush uses COLLECT when configured with COLLECT.
     *
     * @return void
     */
    public function testAutoFlushUsesCollectStrategyWhenConfiguredAsCollect(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, strategy: FlushStrategy::COLLECT, transactions: $this->transactionTracker(...));

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        self::assertNotNull($pool->lastAutoFlushResult());
    }

    /**
     * Test that lastAutoFlushResult returns null before any auto-flush.
     *
     * @return void
     */
    public function testLastAutoFlushResultReturnsNullBeforeAutoFlush(): void
    {
        self::assertNull($this->pool->lastAutoFlushResult());
    }

    /**
     * Test that lastAutoFlushResult returns a result after an auto-flush.
     *
     * @return void
     */
    public function testLastAutoFlushResultReturnsResultAfterAutoFlush(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, transactions: $this->transactionTracker(...));

        $pool->add('testing', 'test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('testing', 'test_records', ['name' => 'b', 'value' => '2']);

        self::assertInstanceOf(WritePoolFlushResult::class, $pool->lastAutoFlushResult());
    }

    /**
     * Test that a flush after COLLECT processes only the retained records.
     *
     * @return void
     */
    public function testSubsequentFlushOnlyAttemptsRetainedRecords(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        self::assertSame(1, DB::table('test_records')->count());
        self::assertSame(1, $pool->count());

        $secondResult = $pool->flush();

        self::assertSame(0, $secondResult->successCount());
        self::assertSame(1, $secondResult->failureCount());
        self::assertSame(1, $pool->count());
    }

    /**
     * Test that a LOG flush clears the buffer regardless of failures.
     *
     * @return void
     */
    public function testFlushWithLogStrategyClearsBufferRegardlessOfFailures(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->once()->withAnyArgs();

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        self::assertTrue($pool->isEmpty());
        self::assertSame(0, $pool->count());
    }

    /**
     * Test that a COLLECT flush accumulates several failed chunks of one table.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyAccumulatesFailedChunksForSameTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('testing', 'nonexistent_table', ['col' => 'a']);
        $pool->add('testing', 'nonexistent_table', ['col' => 'b']);

        $pool->flush();

        self::assertSame(2, $pool->count());
    }

    /**
     * Test that a THROW flush retains the failed chunk and everything after it.
     *
     * @return void
     */
    public function testFlushWithThrowStrategyRetainsExactRemainingRecords(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('testing', 'test_unique', ['name' => 'beta']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        try {
            $pool->flush();
            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException) {
            // Buffer state is inspected below
        }

        self::assertSame(1, DB::table('test_unique')->count());
        self::assertSame(0, DB::table('test_records')->count());
        self::assertSame(3, $pool->count());
    }

    /**
     * Test that a transactional flush rolls back the table on a chunk failure.
     *
     * @return void
     */
    public function testTransactionalFlushRollsBackWholeTableOnChunkFailure(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT, transactional: true);

        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('testing', 'test_unique', ['name' => 'alpha']);

        $pool->flush();

        self::assertSame(0, DB::table('test_unique')->count());
    }

    /**
     * Test that a non-transactional flush persists the healthy chunk.
     *
     * @return void
     */
    public function testNonTransactionalFlushPersistsHealthyChunkOnLaterFailure(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('testing', 'test_unique', ['name' => 'alpha']);

        $pool->flush();

        self::assertSame(1, DB::table('test_unique')->count());
    }

    /**
     * Test that a transactional COLLECT retains all of a rolled-back table.
     *
     * @return void
     */
    public function testTransactionalCollectRetainsWholeRolledBackTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT, transactional: true);

        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('testing', 'test_unique', ['name' => 'alpha']);

        $flushResult = $pool->flush();

        self::assertSame(2, $pool->count());
        self::assertSame(0, $flushResult->flushedRecordCount());
        self::assertSame(2, $flushResult->retainedRecordCount());
        self::assertSame(0, $flushResult->droppedRecordCount());
    }

    /**
     * Test that a transactional THROW retains the rolled-back and later tables.
     *
     * @return void
     */
    public function testTransactionalThrowRollsBackAndRetainsWholeTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW, transactional: true);

        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        try {
            $pool->flush();
            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException) {
            // Buffer state is inspected below
        }

        self::assertSame(0, DB::table('test_unique')->count());
        self::assertSame(3, $pool->count());
    }

    /**
     * Test that a transactional THROW does not retain a committed table.
     *
     * @return void
     */
    public function testTransactionalThrowRetainsUnprocessedTablesButNotCommittedOnes(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW, transactional: true);

        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('testing', 'test_other', ['label' => 'baz']);

        try {
            $pool->flush();
            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException $exception) {
            self::assertSame(3, $exception->flushResult()->retainedRecordCount());
        }

        self::assertSame(1, DB::table('test_records')->count());
        self::assertSame(0, DB::table('test_unique')->count());
        self::assertSame(0, DB::table('test_other')->count());
        self::assertSame(3, $pool->count());
    }

    /**
     * Test that COLLECT reports record counts for a partially failing flush.
     *
     * @return void
     */
    public function testCollectStrategyReportsRecordLevelCounts(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('testing', 'nonexistent_table', ['col' => 'a']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('testing', 'test_records', ['name' => 'baz', 'value' => 'qux']);

        $flushResult = $pool->flush();

        self::assertSame(2, $flushResult->flushedRecordCount());
        self::assertSame(1, $flushResult->failedRecordCount());
        self::assertSame(1, $flushResult->retainedRecordCount());
        self::assertSame(0, $flushResult->droppedRecordCount());
    }

    /**
     * Test that THROW reports record counts for failed and unprocessed records.
     *
     * @return void
     */
    public function testThrowStrategyReportsRecordLevelCounts(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('testing', 'nonexistent_table', ['col' => 'a']);
        $pool->add('testing', 'nonexistent_table', ['col' => 'b']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        try {
            $pool->flush();
            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException $exception) {

            $partialResult = $exception->flushResult();

            self::assertSame(0, $partialResult->flushedRecordCount());
            self::assertSame(1, $partialResult->failedRecordCount());
            self::assertSame(3, $partialResult->retainedRecordCount());
            self::assertSame(0, $partialResult->droppedRecordCount());
        }
    }

    /**
     * Test that LOG reports failed records as dropped.
     *
     * @return void
     */
    public function testLogStrategyReportsDroppedRecordCounts(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->once()->withAnyArgs();

        $pool->add('testing', 'nonexistent_table', ['col' => 'a']);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $pool->flush();

        self::assertSame(1, $flushResult->flushedRecordCount());
        self::assertSame(1, $flushResult->failedRecordCount());
        self::assertSame(0, $flushResult->retainedRecordCount());
        self::assertSame(1, $flushResult->droppedRecordCount());
    }

    /**
     * Test that a successful flush reports every table it attempted to persist.
     *
     * @return void
     */
    public function testFlushResultReportsEveryAttemptedTable(): void
    {
        $this->pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->add('testing', 'test_other', ['label' => 'baz']);

        $flushResult = $this->pool->flush();

        self::assertSame(['test_records', 'test_other'], $flushResult->flushedTables());
    }

    /**
     * Test that an empty flush reports no attempted tables.
     *
     * @return void
     */
    public function testEmptyFlushReportsNoAttemptedTables(): void
    {
        $flushResult = $this->pool->flush();

        self::assertSame([], $flushResult->flushedTables());
    }

    /**
     * Test that the THROW partial result reports every attempted table.
     *
     * @return void
     */
    public function testThrowResultReportsEveryAttemptedTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);

        try {
            $pool->flush();
            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException $exception) {
            self::assertSame(['test_records', 'nonexistent_table'], $exception->flushResult()->flushedTables());
        }
    }

    /**
     * Test that a transactional flush commits and counts every chunk.
     *
     * @return void
     */
    public function testTransactionalFlushCommitsAndRecordsTableSuccess(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT, transactional: true);

        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('testing', 'test_records', ['name' => 'baz', 'value' => 'qux']);

        $flushResult = $pool->flush();

        self::assertTrue($flushResult->isSuccessful());
        self::assertSame(2, $flushResult->successCount());
        self::assertSame(2, $flushResult->flushedRecordCount());
        self::assertSame(2, DB::table('test_records')->count());
        self::assertSame(0, $pool->count());
    }

    /**
     * Test that a transactional LOG flush logs and drops a rolled-back table.
     *
     * @return void
     */
    public function testTransactionalLogFlushLogsAndDropsRolledBackTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::LOG, transactional: true);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->once()->withAnyArgs();

        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('testing', 'test_unique', ['name' => 'alpha']);

        $flushResult = $pool->flush();

        self::assertFalse($flushResult->isSuccessful());
        self::assertSame(0, DB::table('test_unique')->count());
        self::assertTrue($pool->isEmpty());
        self::assertSame(2, $flushResult->droppedRecordCount());
        self::assertSame(0, $flushResult->retainedRecordCount());
    }

    /**
     * Test that a successful flush invalidates each table's per-query cache.
     *
     * @return void
     */
    public function testFlushInvalidatesTheQueryCacheForFlushedTables(): void
    {
        Config::set('cache.default', 'array');
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', true);

        $warm = $this->cacheStore('test_records');
        $warm->put('stale-query-hash', collect(['stale']), 60);

        self::assertNotNull($warm->fetch('stale-query-hash'));

        $this->pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->flush();

        self::assertNull($this->cacheStore('test_records')->fetch('stale-query-hash'));
    }

    /**
     * Test that a limit-triggered auto-flush invalidates the per-query cache.
     *
     * @return void
     */
    public function testAutoFlushAtPoolLimitInvalidatesTheQueryCache(): void
    {
        Config::set('cache.default', 'array');
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', true);

        $warm = $this->cacheStore('test_records');
        $warm->put('stale-query-hash', collect(['stale']), 60);

        $pool = new WritePool(chunkSize: 500, poolLimit: 1, transactions: $this->transactionTracker(...));
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        self::assertSame(1, DB::table('test_records')->count());
        self::assertNull($this->cacheStore('test_records')->fetch('stale-query-hash'));
    }

    /**
     * Test that a flush fails when the invalidation key is absent, rather than
     * guessing whether to invalidate.
     *
     * @return void
     */
    public function testFlushFailsWhenTheInvalidationKeyIsAbsent(): void
    {
        $deferred = (array) Config::get('api-toolkit.deferred_writes');
        unset($deferred['invalidate_query_cache']);
        Config::set('api-toolkit.deferred_writes', $deferred);

        $this->pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.deferred_writes.invalidate_query_cache setting is missing; add it from the published config.');

        $this->pool->flush();
    }

    /**
     * Test that a flush leaves the cache untouched when disabled by config.
     *
     * @return void
     */
    public function testFlushDoesNotInvalidateTheQueryCacheWhenDisabled(): void
    {
        Config::set('cache.default', 'array');
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', false);

        $warm = $this->cacheStore('test_records');
        $warm->put('stale-query-hash', collect(['stale']), 60);

        $this->pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->flush();

        self::assertNotNull($this->cacheStore('test_records')->fetch('stale-query-hash'));
    }

    /**
     * Test that a throwing flush still invalidates the tables it persisted.
     *
     * @return void
     */
    public function testFlushInvalidatesTheQueryCacheForTablesPersistedBeforeAThrow(): void
    {
        Config::set('cache.default', 'array');
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', true);

        $warm = $this->cacheStore('test_records');
        $warm->put('stale-query-hash', collect(['stale']), 60);

        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW);
        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);

        try {
            $pool->flush();
            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException) {
            // Expected
        }

        self::assertNull($this->cacheStore('test_records')->fetch('stale-query-hash'));
    }

    /**
     * Test that COLLECT absorbs a failure of every record without throwing.
     *
     * @return void
     */
    public function testCollectStrategySystemicFailureCollectsEveryFailureWithoutThrowing(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('testing', 'nonexistent_table', ['col' => 'a']);
        $pool->add('testing', 'nonexistent_table', ['col' => 'b']);
        $pool->add('testing', 'another_missing_table', ['col' => 'c']);

        $flushResult = $pool->flush();

        self::assertFalse($flushResult->isSuccessful());
        self::assertSame(0, $flushResult->successCount());
        self::assertSame(2, $flushResult->failureCount());
        self::assertSame(0, $flushResult->flushedRecordCount());
        self::assertSame(3, $flushResult->failedRecordCount());
        self::assertSame(3, $flushResult->retainedRecordCount());
        self::assertSame(0, $flushResult->droppedRecordCount());
        self::assertArrayHasKey('nonexistent_table', $flushResult->failures()['testing']);
        self::assertArrayHasKey('another_missing_table', $flushResult->failures()['testing']);
        self::assertSame(3, $pool->count());
    }

    /**
     * Test that records retained by COLLECT persist on a later flush.
     *
     * @return void
     */
    public function testCollectRetainedRecordsPersistOnRetryAfterCauseResolved(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('testing', 'test_deferred_retry', ['label' => 'first']);
        $pool->add('testing', 'test_deferred_retry', ['label' => 'second']);

        $firstResult = $pool->flush();

        self::assertFalse($firstResult->isSuccessful());
        self::assertSame(2, $pool->count());

        Schema::create('test_deferred_retry', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        $secondResult = $pool->flush();

        self::assertTrue($secondResult->isSuccessful());
        self::assertSame(2, $secondResult->flushedRecordCount());
        self::assertSame(0, $pool->count());
        self::assertSame(['first', 'second'], DB::table('test_deferred_retry')->orderBy('id')->pluck('label')->all());
    }

    /**
     * Test that a non-transactional flush commits chunks around a failing one.
     *
     * @return void
     */
    public function testNonTransactionalMiddleChunkFailureCommitsSurroundingChunks(): void
    {
        $pool = new WritePool(chunkSize: 2, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('testing', 'test_unique', ['name' => 'beta']);
        $pool->add('testing', 'test_unique', ['name' => 'gamma']);
        $pool->add('testing', 'test_unique', ['name' => 'gamma']);
        $pool->add('testing', 'test_unique', ['name' => 'delta']);
        $pool->add('testing', 'test_unique', ['name' => 'epsilon']);

        $flushResult = $pool->flush();

        self::assertSame(2, $flushResult->successCount());
        self::assertSame(1, $flushResult->failureCount());
        self::assertSame(4, $flushResult->flushedRecordCount());
        self::assertSame(2, $flushResult->failedRecordCount());
        self::assertSame(['alpha', 'beta', 'delta', 'epsilon'], DB::table('test_unique')->orderBy('id')->pluck('name')->all());
        self::assertSame(2, $pool->count());
    }

    /**
     * Test that a transactional COLLECT flush scopes its transaction per table.
     *
     * @return void
     */
    public function testTransactionalCollectCommitsEarlierTableAndRollsBackLaterTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT, transactional: true);

        $pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('testing', 'test_records', ['name' => 'baz', 'value' => 'qux']);
        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('testing', 'test_unique', ['name' => 'alpha']);

        $flushResult = $pool->flush();

        self::assertSame(2, DB::table('test_records')->count());
        self::assertSame(0, DB::table('test_unique')->count());
        self::assertSame(2, $flushResult->flushedRecordCount());
        self::assertSame(2, $flushResult->retainedRecordCount());
        self::assertSame(0, $flushResult->droppedRecordCount());
        self::assertSame(2, $pool->count());
        self::assertArrayHasKey('test_unique', $flushResult->failures()['testing']);
        self::assertArrayNotHasKey('test_records', $flushResult->failures()['testing']);
    }

    /**
     * Test that one table name on two connections keeps two buffers, each
     * flushed to its own connection.
     *
     * @return void
     */
    public function testTargetsWithOneTableNameFlushToTheirOwnConnection(): void
    {
        $this->useAuditTestTables();

        $this->pool->add('testing', 'test_records', ['name' => 'main', 'value' => '1']);
        $this->pool->add('audit', 'test_records', ['name' => 'audit', 'value' => '2']);
        $this->pool->add('audit', 'test_unique', ['name' => 'alpha']);

        self::assertSame(3, $this->pool->count());

        $flushResult = $this->pool->flush();

        self::assertSame(3, $flushResult->successCount());
        self::assertSame(['test_records', 'test_unique'], $flushResult->flushedTables());
        self::assertSame(['main'], DB::table('test_records')->pluck('name')->all());
        self::assertSame(['audit'], DB::connection('audit')->table('test_records')->pluck('name')->all());
    }

    /**
     * Test that the transactional mode rolls back the failing target on its own
     * connection and leaves the other connection committed.
     *
     * @return void
     */
    public function testTransactionalFlushRollsBackTheTargetConnectionOnly(): void
    {
        $this->useAuditTestTables();

        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT, transactional: true);

        $pool->add('testing', 'test_unique', ['name' => 'alpha']);
        $pool->add('audit', 'test_unique', ['name' => 'alpha']);
        $pool->add('audit', 'test_unique', ['name' => 'alpha']);

        $flushResult = $pool->flush();

        self::assertSame(1, DB::table('test_unique')->count());
        self::assertSame(0, DB::connection('audit')->table('test_unique')->count());
        self::assertSame(2, $pool->count());
        self::assertSame(['audit'], array_keys($flushResult->failures()));
    }

    /**
     * Test that rows of different column sets insert as separate statements,
     * each value in its own column.
     *
     * @return void
     */
    public function testRowsOfDifferentColumnSetsInsertAsSeparateStatements(): void
    {
        $inserts = $this->countInserts(function (): void {
            $this->pool->add('testing', 'test_shapes', ['a' => 'first']);
            $this->pool->add('testing', 'test_shapes', ['b' => 'second']);
            $this->pool->flush();
        });

        self::assertSame(2, $inserts);
        self::assertSame([['first', null], [null, 'second']], $this->shapeRows(['a', 'b']));
    }

    /**
     * Test that rows of one column set in any key order insert as one
     * statement.
     *
     * @return void
     */
    public function testRowsOfOneColumnSetInsertAsOneStatement(): void
    {
        $inserts = $this->countInserts(function (): void {
            $this->pool->add('testing', 'test_shapes', ['a' => 'one', 'b' => 'two']);
            $this->pool->add('testing', 'test_shapes', ['b' => 'four', 'a' => 'three']);
            $this->pool->flush();
        });

        self::assertSame(1, $inserts);
        self::assertSame([['one', 'two'], ['three', 'four']], $this->shapeRows(['a', 'b']));
    }

    /**
     * Test that a row omitting a column keeps the column's database default.
     *
     * @return void
     */
    public function testRowOmittingAColumnKeepsItsDefault(): void
    {
        $this->pool->add('testing', 'test_shapes', ['a' => 'one', 'c' => 'given']);
        $this->pool->add('testing', 'test_shapes', ['a' => 'two']);

        self::assertTrue($this->pool->flush()->isSuccessful());
        self::assertSame([['one', 'given'], ['two', 'fallback']], $this->shapeRows(['a', 'c']));
    }

    /**
     * Test that runs are consecutive, chunked on their own, and keep the
     * insertion order.
     *
     * @return void
     */
    public function testRunsKeepInsertionOrderAndChunkOnTheirOwn(): void
    {
        $pool = new WritePool(chunkSize: 2, poolLimit: 10000);

        $pool->add('testing', 'test_shapes', ['a' => 'one']);
        $pool->add('testing', 'test_shapes', ['a' => 'two']);
        $pool->add('testing', 'test_shapes', ['a' => 'three']);
        $pool->add('testing', 'test_shapes', ['b' => 'four']);
        $pool->add('testing', 'test_shapes', ['a' => 'five']);

        self::assertSame(4, $pool->flush()->successCount());
        self::assertSame([
            ['one', null],
            ['two', null],
            ['three', null],
            [null, 'four'],
            ['five', null],
        ], $this->shapeRows(['a', 'b']));
    }

    /**
     * Test that failures and retained records are keyed by connection, then
     * table.
     *
     * @return void
     */
    public function testFailuresAndRetainedRecordsAreKeyedByConnectionAndTable(): void
    {
        $this->useAuditTestTables();

        $this->pool->add('audit', 'nonexistent_table', ['col' => 'val']);
        $this->pool->add('testing', 'test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $this->pool->flush();

        self::assertSame(['audit'], array_keys($flushResult->failures()));
        self::assertSame([['col' => 'val']], $flushResult->failures()['audit']['nonexistent_table'][0]['records']);
        self::assertSame(1, $this->pool->count());

        $retry = $this->pool->flush();

        self::assertSame(0, $retry->successCount());
        self::assertSame(['nonexistent_table'], $retry->flushedTables());
        self::assertSame(1, DB::table('test_records')->count());
    }

    /**
     * Test that a target on an unconfigured connection fails under the
     * strategy, so rows flushed before it are not retried.
     *
     * @return void
     */
    public function testUnconfiguredConnectionFailsItsTargetOnly(): void
    {
        $this->pool->add('testing', 'test_records', ['name' => 'kept', 'value' => '1']);
        $this->pool->add('missing', 'test_records', ['name' => 'lost', 'value' => '2']);

        $flushResult = $this->pool->flush();

        self::assertSame(['missing'], array_keys($flushResult->failures()));
        self::assertSame(1, $this->pool->count());

        $this->pool->flush();

        self::assertSame(1, DB::table('test_records')->count());
    }

    /**
     * Test that the transactional mode fails a target on an unconfigured
     * connection under the strategy, so rows flushed before it are not retried.
     *
     * @return void
     */
    public function testTransactionalUnconfiguredConnectionFailsItsTargetOnly(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, transactional: true);

        $pool->add('testing', 'test_records', ['name' => 'kept', 'value' => '1']);
        $pool->add('missing', 'test_records', ['name' => 'lost', 'value' => '2']);

        self::assertSame(['missing'], array_keys($pool->flush()->failures()));

        $pool->flush();

        self::assertSame(1, DB::table('test_records')->count());
        self::assertSame(1, $pool->count());
    }

    /**
     * Test that the pool limit reached with an unconfigured connection buffered
     * still auto-flushes, failing that target under the strategy.
     *
     * @return void
     */
    public function testAutoFlushTreatsAnUnconfiguredConnectionAsIdle(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, transactions: $this->transactionTracker(...));

        $pool->add('missing', 'test_records', ['name' => 'lost', 'value' => '1']);
        $pool->add('testing', 'test_records', ['name' => 'kept', 'value' => '2']);

        self::assertSame(1, DB::table('test_records')->count());
        self::assertSame(['missing'], array_keys($pool->lastAutoFlushResult()?->failures() ?? []));
        self::assertSame(1, $pool->count());
    }

    /**
     * Test that the pool limit inside a transaction on the target connection
     * does not flush, and the next add after commit does.
     *
     * @return void
     */
    public function testAutoFlushWaitsForTheTargetTransactionToCommit(): void
    {
        $this->useAuditTestTables();

        $pool = new WritePool(chunkSize: 500, poolLimit: 2, transactions: $this->transactionTracker(...));

        DB::connection('audit')->transaction(function () use ($pool): void {
            $pool->add('audit', 'test_records', ['name' => 'a', 'value' => '1']);
            $pool->add('audit', 'test_records', ['name' => 'b', 'value' => '2']);

            self::assertSame(0, DB::connection('audit')->table('test_records')->count());
        });

        self::assertSame(2, $pool->count());
        self::assertNull($pool->lastAutoFlushResult());

        $pool->add('audit', 'test_records', ['name' => 'c', 'value' => '3']);

        self::assertSame(3, DB::connection('audit')->table('test_records')->count());
        self::assertSame(0, $pool->count());
    }

    /**
     * Test that the pool limit inside a transaction on the default connection
     * does not flush, and the boundary flush after commit does.
     *
     * @return void
     */
    public function testAutoFlushWaitsForATransactionOnTheDefaultConnection(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 1, transactions: $this->transactionTracker(...));

        DB::transaction(function () use ($pool): void {
            $pool->add('testing', 'test_records', ['name' => 'a', 'value' => '1']);
        });

        self::assertSame(0, DB::table('test_records')->count());
        self::assertSame(1, $pool->count());

        $pool->flush();

        self::assertSame(1, DB::table('test_records')->count());
    }

    /**
     * Test that rows buffered in a rolled-back transaction stay buffered for
     * the boundary flush.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testRolledBackTransactionLeavesRowsForTheBoundaryFlush(): void
    {
        $this->useAuditTestTables();

        $pool = new WritePool(chunkSize: 500, poolLimit: 2, transactions: $this->transactionTracker(...));

        try {
            DB::connection('audit')->transaction(function () use ($pool): void {
                $pool->add('audit', 'test_records', ['name' => 'a', 'value' => '1']);
                $pool->add('audit', 'test_records', ['name' => 'b', 'value' => '2']);

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
            // The rows stay buffered past the rollback
        }

        self::assertSame(2, $pool->count());

        $pool->flush();

        self::assertSame(2, DB::connection('audit')->table('test_records')->count());
    }

    /**
     * Test that a target on another, idle connection still auto-flushes while
     * one connection is inside a transaction.
     *
     * @return void
     */
    public function testAutoFlushStillFlushesAnIdleConnection(): void
    {
        $this->useAuditTestTables();

        $pool = new WritePool(chunkSize: 500, poolLimit: 2, transactions: $this->transactionTracker(...));

        DB::connection('audit')->transaction(function () use ($pool): void {
            $pool->add('audit', 'test_records', ['name' => 'a', 'value' => '1']);
            $pool->add('testing', 'test_records', ['name' => 'b', 'value' => '2']);
        });

        self::assertSame(1, DB::table('test_records')->count());
        self::assertSame(0, DB::connection('audit')->table('test_records')->count());
        self::assertSame(1, $pool->count());
        self::assertSame(['test_records'], $pool->lastAutoFlushResult()?->flushedTables());
    }

    /**
     * Test that without a transaction tracker every open transaction counts,
     * the test's wrapping one included.
     *
     * @return void
     */
    public function testAutoFlushWithoutATrackerCountsEveryOpenTransaction(): void
    {
        $this->useAuditTestTables();

        $pool = new WritePool(chunkSize: 500, poolLimit: 1);

        $pool->add('testing', 'test_records', ['name' => 'a', 'value' => '1']);

        self::assertSame(0, DB::table('test_records')->count());

        DB::connection('audit')->transaction(function () use ($pool): void {
            $pool->add('audit', 'test_records', ['name' => 'b', 'value' => '2']);
        });

        self::assertSame(0, DB::connection('audit')->table('test_records')->count());

        $pool->add('audit', 'test_records', ['name' => 'c', 'value' => '3']);

        self::assertSame(2, DB::connection('audit')->table('test_records')->count());
        self::assertSame(1, $pool->count());
    }

    /**
     * Test that a transaction the tracker never saw still counts as open, so
     * only the test's wrapping transaction is discounted.
     *
     * @return void
     */
    public function testAutoFlushCountsATransactionTheTrackerDoesNotHold(): void
    {
        $this->useAuditTestTables();

        $pool = new WritePool(chunkSize: 500, poolLimit: 1, transactions: fn (): DatabaseTransactionsManager => new DatabaseTransactionsManager);

        DB::connection('audit')->transaction(function () use ($pool): void {
            $pool->add('audit', 'test_records', ['name' => 'a', 'value' => '1']);
        });

        self::assertSame(0, DB::connection('audit')->table('test_records')->count());
        self::assertSame(1, $pool->count());
    }

    /**
     * Test that a pool limit reached with every buffered connection inside a
     * transaction records no auto-flush, even after an earlier flush emptied
     * another connection.
     *
     * @return void
     */
    public function testAutoFlushRecordsNothingWhenNoBufferedConnectionIsIdle(): void
    {
        $this->useAuditTestTables();

        $pool = new WritePool(chunkSize: 500, poolLimit: 2, transactions: $this->transactionTracker(...));

        $pool->add('testing', 'test_records', ['name' => 'a', 'value' => '1']);
        $pool->flush();

        DB::connection('audit')->transaction(function () use ($pool): void {
            $pool->add('audit', 'test_records', ['name' => 'b', 'value' => '2']);
            $pool->add('audit', 'test_records', ['name' => 'c', 'value' => '3']);
        });

        self::assertNull($pool->lastAutoFlushResult());
        self::assertSame(2, $pool->count());
    }

    /**
     * Test that a throwing auto-flush counts only the records of the
     * connections it flushed as retained.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testThrowingAutoFlushCountsOnlyFlushedConnectionsAsRetained(): void
    {
        $this->useAuditTestTables();

        $pool = new WritePool(chunkSize: 500, poolLimit: 2, strategy: FlushStrategy::THROW, transactions: $this->transactionTracker(...));

        try {
            DB::connection('audit')->transaction(function () use ($pool): void {
                $pool->add('audit', 'test_records', ['name' => 'a', 'value' => '1']);
                $pool->add('testing', 'nonexistent_table', ['col' => 'val']);
            });

            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException $exception) {
            self::assertSame(1, $exception->flushResult()->retainedRecordCount());
            self::assertSame(['nonexistent_table'], $exception->flushResult()->flushedTables());
        }

        self::assertSame(2, $pool->count());
    }

    /**
     * Test that records a collecting auto-flush kept do not count toward the
     * pool limit, so they are retried once per limit's worth of new records and
     * the new records insert as full chunks.
     *
     * @return void
     */
    public function testRetainedRecordsAreRetriedOncePerPoolLimitOfNewRecords(): void
    {
        $pool    = new WritePool(chunkSize: 500, poolLimit: 3, transactions: $this->transactionTracker(...));
        $inserts = [];

        DB::connection()->beforeExecuting(function (string $query) use (&$inserts): void {
            $inserts[] = $query;
        });

        for ($i = 0; $i < 3; $i++) {
            $pool->add('testing', 'nonexistent_table', ['col' => "poison_{$i}"]);
        }

        for ($i = 0; $i < 9; $i++) {
            $pool->add('testing', 'test_records', ['name' => "name_{$i}", 'value' => "value_{$i}"]);
        }

        self::assertCount(4, array_filter($inserts, fn (string $query): bool => str_contains($query, 'nonexistent_table')));
        self::assertCount(3, array_filter($inserts, fn (string $query): bool => str_contains($query, 'test_records')));
        self::assertSame(9, DB::table('test_records')->count());
        self::assertSame(3, $pool->count());
    }

    /**
     * Test that the records a throwing auto-flush kept do not trigger another
     * auto-flush on the next deferral.
     *
     * @return void
     */
    public function testAThrowingAutoFlushIsNotRetriedOnTheNextDeferral(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, strategy: FlushStrategy::THROW, transactions: $this->transactionTracker(...));

        $pool->add('testing', 'nonexistent_table', ['col' => 'val']);

        try {
            $pool->add('testing', 'test_records', ['name' => 'a', 'value' => '1']);

            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException) {
            // Both records stay buffered
        }

        $pool->add('testing', 'test_records', ['name' => 'b', 'value' => '2']);

        self::assertSame(3, $pool->count());
        self::assertSame(0, DB::table('test_records')->count());
    }

    /**
     * Test that a connection's kept records stay uncounted while it holds an
     * open transaction and another connection flushes.
     *
     * @return void
     */
    public function testRecordsAConnectionKeptStayUncountedWhenAnotherConnectionFlushes(): void
    {
        $this->useAuditTestTables();

        $pool = new WritePool(chunkSize: 500, poolLimit: 2, transactions: $this->transactionTracker(...));

        $pool->add('testing', 'nonexistent_table', ['col' => 'a']);
        $pool->add('testing', 'nonexistent_table', ['col' => 'b']);

        DB::transaction(function () use ($pool): void {
            $pool->add('audit', 'test_records', ['name' => 'a', 'value' => '1']);
            $pool->add('audit', 'test_records', ['name' => 'b', 'value' => '2']);
            $pool->add('audit', 'test_records', ['name' => 'c', 'value' => '3']);
        });

        self::assertSame(2, DB::connection('audit')->table('test_records')->count());
        self::assertSame(3, $pool->count());
    }

    /**
     * Test that a connection holding only records a flush kept is not flushed
     * again while new records wait on a connection inside a transaction.
     *
     * @return void
     */
    public function testAConnectionWithoutNewRecordsIsNotRetriedWhileAnotherWaits(): void
    {
        $this->useAuditTestTables();

        $pool     = new WritePool(chunkSize: 500, poolLimit: 2, transactions: $this->transactionTracker(...));
        $attempts = 0;

        DB::connection()->beforeExecuting(function () use (&$attempts): void {
            $attempts++;
        });

        $pool->add('testing', 'nonexistent_table', ['col' => 'a']);
        $pool->add('testing', 'nonexistent_table', ['col' => 'b']);

        DB::connection('audit')->transaction(function () use ($pool): void {
            $pool->add('audit', 'test_records', ['name' => 'a', 'value' => '1']);
            $pool->add('audit', 'test_records', ['name' => 'b', 'value' => '2']);
            $pool->add('audit', 'test_records', ['name' => 'c', 'value' => '3']);
        });

        self::assertSame(1, $attempts);
        self::assertSame(5, $pool->count());
    }

    /**
     * Test that each chunk flushed inside an open transaction runs in its own
     * savepoint, and outside one runs bare.
     *
     * @return void
     */
    public function testAChunkFlushedInsideATransactionRunsInItsOwnSavepoint(): void
    {
        $this->useAuditTestTables();

        $pool   = new WritePool(chunkSize: 1, poolLimit: 10000);
        $levels = [];

        DB::listen(function (QueryExecuted $query) use (&$levels): void {
            if ($query->connectionName !== 'audit' || !str_starts_with($query->sql, 'insert')) {
                return;
            }

            $levels[] = $query->connection->transactionLevel();
        });

        $pool->add('audit', 'test_records', ['name' => 'a', 'value' => '1']);
        $pool->flush();

        DB::connection('audit')->transaction(function () use ($pool): void {
            $pool->add('audit', 'test_records', ['name' => 'b', 'value' => '2']);
            $pool->add('audit', 'test_records', ['name' => 'c', 'value' => '3']);
            $pool->flush();
        });

        self::assertSame([0, 2, 2], $levels);
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

        Schema::create('test_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('value');
        });

        Schema::create('test_other', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        Schema::create('test_unique', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
        });

        Schema::create('test_shapes', function (Blueprint $table): void {
            $table->id();
            $table->string('a')->nullable();
            $table->string('b')->nullable();
            $table->string('c')->default('fallback');
        });
    }

    /**
     * Configure the second connection holding the test tables.
     *
     * @return void
     */
    private function useAuditTestTables(): void
    {
        $this->useAuditConnection();

        Schema::connection('audit')->create('test_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('value');
        });

        Schema::connection('audit')->create('test_unique', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
        });
    }

    /**
     * Count the INSERT statements the callback runs on the default connection.
     *
     * @param  callable(): void  $callback
     * @return int
     */
    private function countInserts(callable $callback): int
    {
        DB::enableQueryLog();

        $callback();

        $inserts = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_starts_with($query['query'], 'insert'),
        )->count();

        DB::disableQueryLog();

        return $inserts;
    }

    /**
     * Read the given columns of every shapes row in insertion order.
     *
     * @param  list<string>  $columns
     * @return array<int, list<mixed>>
     */
    private function shapeRows(array $columns): array
    {
        return DB::table('test_shapes')->orderBy('id')->get($columns)
            ->map(fn (object $row): array => array_values((array) $row))
            ->all();
    }

    /**
     * Build a CacheStore on the array driver for the given table.
     *
     * @param  string  $table
     * @return \SineMacula\Repositories\Concerns\CacheStore
     */
    private function cacheStore(string $table): CacheStore
    {
        return new CacheStore(Cache::store('array'), $table, new CacheStoreOptions(3600, new CacheSizeGuard(null, null), true, 0));
    }
}
