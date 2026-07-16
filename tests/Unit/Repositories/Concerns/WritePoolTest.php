<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;
use SineMacula\Repositories\Concerns\CacheSizeGuard;
use SineMacula\Repositories\Concerns\CacheStore;
use SineMacula\Repositories\Concerns\CacheStoreOptions;
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
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

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
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->add('test_other', ['label' => 'baz']);

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
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        self::assertFalse($this->pool->isEmpty());
    }

    /**
     * Test that flush inserts records into the database.
     *
     * @return void
     */
    public function testFlushInsertsRecordsIntoDatabase(): void
    {
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->add('test_records', ['name' => 'baz', 'value' => 'qux']);

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
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->add('test_other', ['label' => 'baz']);

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
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

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
            $pool->add('test_records', ['name' => "name_{$i}", 'value' => "value_{$i}"]);
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
     * Test that the default strategy continues past a chunk failure without
     * logging an error and persists the healthy chunk.
     *
     * @return void
     */
    public function testDefaultStrategyContinuesOnChunkFailureWithoutErrorLog(): void
    {
        $pool = new WritePool(chunkSize: 2, poolLimit: 10000);

        Log::shouldReceive('error')->never();

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $pool->flush();

        self::assertFalse($flushResult->isSuccessful());
        self::assertSame(1, $flushResult->failureCount());
        self::assertSame(1, DB::table('test_records')->count());
    }

    /**
     * Test that the default strategy retains failed records in the buffer
     * rather than dropping them after a partial failure.
     *
     * @return void
     */
    public function testDefaultStrategyRetainsBufferAfterPartialFailure(): void
    {
        Log::shouldReceive('error')->never();

        $this->pool->add('nonexistent_table', ['col' => 'val']);

        $this->pool->flush();

        self::assertSame(1, $this->pool->count());
        self::assertFalse($this->pool->isEmpty());
    }

    /**
     * Test that the default strategy is collect, surfacing failures loudly in
     * the result with zero dropped records.
     *
     * @return void
     */
    public function testDefaultStrategyIsCollectAndDropsNoRecords(): void
    {
        Log::shouldReceive('error')->never();

        $this->pool->add('nonexistent_table', ['col' => 'val']);
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $this->pool->flush();

        self::assertFalse($flushResult->isSuccessful());
        self::assertSame(0, $flushResult->droppedRecordCount());
        self::assertSame(1, $flushResult->retainedRecordCount());
        self::assertSame(1, $this->pool->count());
    }

    /**
     * Test that add triggers auto-flush when pool limit is reached.
     *
     * @return void
     */
    public function testAddTriggersAutoFlushWhenPoolLimitReached(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 3);

        $pool->add('test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('test_records', ['name' => 'b', 'value' => '2']);
        $pool->add('test_records', ['name' => 'c', 'value' => '3']);

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
        $pool = new WritePool(chunkSize: 500, poolLimit: 2);

        $pool->add('test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('test_records', ['name' => 'b', 'value' => '2']);

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
        $pool = new WritePool(chunkSize: 500, poolLimit: 2);

        $pool->add('test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('test_records', ['name' => 'b', 'value' => '2']);

        self::assertSame(0, $pool->count());

        $pool->add('test_records', ['name' => 'c', 'value' => '3']);

        self::assertSame(1, $pool->count());
        self::assertSame(2, DB::table('test_records')->count());
    }

    /**
     * Test that flush with LOG strategy returns a result containing failure
     * details keyed by the failing table.
     *
     * @return void
     */
    public function testFlushWithLogStrategyReturnsResultWithFailureDetails(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->once()->withAnyArgs();

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $pool->flush();

        self::assertFalse($flushResult->isSuccessful());
        self::assertSame(1, $flushResult->failureCount());
        self::assertSame(1, $flushResult->successCount());
        self::assertArrayHasKey('nonexistent_table', $flushResult->failures());
        self::assertSame([['col' => 'val']], $flushResult->failures()['nonexistent_table'][0]['records']);
        self::assertNotEmpty($flushResult->failures()['nonexistent_table'][0]['exception']);
        self::assertSame(QueryException::class, $flushResult->failures()['nonexistent_table'][0]['exception_class']);
    }

    /**
     * Test that a LOG strategy chunk failure logs the failing table name and
     * the underlying error message in the log context.
     *
     * @return void
     */
    public function testLogStrategyFailureLogsTableAndErrorContext(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')
            ->once()
            ->with(
                \Mockery::type('string'),
                \Mockery::on(fn (array $context): bool => ($context['table'] ?? null) === 'nonexistent_table'
                    && is_string($context['error'] ?? null)
                    && $context['error'] !== ''),
            );

        $pool->add('nonexistent_table', ['col' => 'val']);

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

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $this->expectException(WritePoolFlushException::class);

        $pool->flush();
    }

    /**
     * Test that flush with THROW strategy preserves failed and unprocessed
     * records in the buffer.
     *
     * @return void
     */
    public function testFlushWithThrowStrategyPreservesFailedAndUnprocessedRecords(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        try {
            $pool->flush();
        } catch (WritePoolFlushException) {
            // Exception intentionally caught to inspect buffer state below
        }

        self::assertFalse($pool->isEmpty());
        self::assertSame(2, $pool->count());
    }

    /**
     * Test that flush with THROW strategy removes successfully inserted records
     * from the buffer.
     *
     * @return void
     */
    public function testFlushWithThrowStrategyRemovesSuccessfullyInsertedRecords(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('test_records', ['name' => 'b', 'value' => '2']);
        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_other', ['label' => 'baz']);

        try {
            $pool->flush();
        } catch (WritePoolFlushException) {
            // Exception intentionally caught to inspect buffer state below
        }

        self::assertSame(2, DB::table('test_records')->count());
        self::assertSame(2, $pool->count());
    }

    /**
     * Test that the WritePoolFlushException thrown by the THROW strategy
     * contains a partial result with correct counts.
     *
     * @return void
     */
    public function testFlushWithThrowStrategyExceptionContainsPartialResult(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('nonexistent_table', ['col' => 'val']);

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
     * Test that flush with COLLECT strategy continues processing after a chunk
     * failure.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyContinuesAfterFailure(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        self::assertSame(1, DB::table('test_records')->count());
    }

    /**
     * Test that flush with COLLECT strategy retains failed records in the
     * buffer.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyRetainsFailedRecordsInBuffer(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        self::assertSame(1, $pool->count());
        self::assertFalse($pool->isEmpty());
    }

    /**
     * Test that flush with COLLECT strategy removes successful records from the
     * buffer while retaining failed records.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyRemovesSuccessfulRecordsFromBuffer(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        self::assertSame(1, DB::table('test_records')->count());
        self::assertSame(1, $pool->count());
    }

    /**
     * Test that flush with COLLECT strategy returns a result containing
     * failures for all failing tables.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyReturnsResultWithAllFailures(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('another_missing_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $pool->flush();

        self::assertSame(2, $flushResult->failureCount());
        self::assertSame(1, $flushResult->successCount());
        self::assertArrayHasKey('nonexistent_table', $flushResult->failures());
        self::assertArrayHasKey('another_missing_table', $flushResult->failures());
    }

    /**
     * Test that flush accepts a per-call strategy override that takes
     * precedence over the instance default.
     *
     * @return void
     */
    public function testFlushStrategyOverridePerCall(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->never();

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush(FlushStrategy::COLLECT);

        self::assertSame(1, $pool->count());
        self::assertFalse($pool->isEmpty());
    }

    /**
     * Test that auto-flush uses the LOG strategy when the pool is configured
     * with LOG.
     *
     * @return void
     */
    public function testAutoFlushUsesLogStrategyWhenConfiguredAsLog(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->once()->withAnyArgs();

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        self::assertSame(0, $pool->count());
    }

    /**
     * Test that memory-pressure auto-flush honours the throw strategy, raising
     * a WritePoolFlushException out of add().
     *
     * @return void
     */
    public function testAutoFlushThrowsWhenConfiguredAsThrow(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, strategy: FlushStrategy::THROW);

        $pool->add('nonexistent_table', ['col' => 'val']);

        $this->expectException(WritePoolFlushException::class);

        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
    }

    /**
     * Test that auto-flush uses COLLECT strategy when the pool is configured
     * with COLLECT.
     *
     * @return void
     */
    public function testAutoFlushUsesCollectStrategyWhenConfiguredAsCollect(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        self::assertNotNull($pool->lastAutoFlushResult());
    }

    /**
     * Test that lastAutoFlushResult returns null before any auto-flush has
     * occurred.
     *
     * @return void
     */
    public function testLastAutoFlushResultReturnsNullBeforeAutoFlush(): void
    {
        self::assertNull($this->pool->lastAutoFlushResult());
    }

    /**
     * Test that lastAutoFlushResult returns a WritePoolFlushResult instance
     * after an auto-flush has been triggered.
     *
     * @return void
     */
    public function testLastAutoFlushResultReturnsResultAfterAutoFlush(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2);

        $pool->add('test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('test_records', ['name' => 'b', 'value' => '2']);

        self::assertInstanceOf(WritePoolFlushResult::class, $pool->lastAutoFlushResult());
    }

    /**
     * Test that a subsequent flush after a COLLECT flush only processes the
     * retained failed records.
     *
     * @return void
     */
    public function testSubsequentFlushOnlyAttemptsRetainedRecords(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        self::assertSame(1, DB::table('test_records')->count());
        self::assertSame(1, $pool->count());

        $secondResult = $pool->flush();

        self::assertSame(0, $secondResult->successCount());
        self::assertSame(1, $secondResult->failureCount());
        self::assertSame(1, $pool->count());
    }

    /**
     * Test that flush with LOG strategy clears the buffer regardless of any
     * failures that occurred.
     *
     * @return void
     */
    public function testFlushWithLogStrategyClearsBufferRegardlessOfFailures(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->once()->withAnyArgs();

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        self::assertTrue($pool->isEmpty());
        self::assertSame(0, $pool->count());
    }

    /**
     * Test that flush with COLLECT strategy accumulates multiple failed chunks
     * for the same table in the retained buffer.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyAccumulatesFailedChunksForSameTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'a']);
        $pool->add('nonexistent_table', ['col' => 'b']);

        $pool->flush();

        self::assertSame(2, $pool->count());
    }

    /**
     * Test that flush with THROW strategy retains exactly the failed chunk, the
     * remaining chunks of the failing table, and the untouched subsequent
     * tables.
     *
     * @return void
     */
    public function testFlushWithThrowStrategyRetainsExactRemainingRecords(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'beta']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        try {
            $pool->flush();
            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException) {
            // Exception intentionally caught to inspect buffer state below
        }

        self::assertSame(1, DB::table('test_unique')->count());
        self::assertSame(0, DB::table('test_records')->count());
        self::assertSame(3, $pool->count());
    }

    /**
     * Test that a transactional flush rolls back the entire table when any
     * chunk fails, leaving none of that table's rows persisted.
     *
     * @return void
     */
    public function testTransactionalFlushRollsBackWholeTableOnChunkFailure(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT, transactional: true);

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);

        $pool->flush();

        self::assertSame(0, DB::table('test_unique')->count());
    }

    /**
     * Test that a non-transactional flush persists the healthy chunk even when
     * a later chunk in the same table fails.
     *
     * @return void
     */
    public function testNonTransactionalFlushPersistsHealthyChunkOnLaterFailure(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);

        $pool->flush();

        self::assertSame(1, DB::table('test_unique')->count());
    }

    /**
     * Test that a transactional collect flush retains every record of a
     * rolled-back table for retry, including the rows that committed before the
     * failing chunk.
     *
     * @return void
     */
    public function testTransactionalCollectRetainsWholeRolledBackTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT, transactional: true);

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);

        $flushResult = $pool->flush();

        self::assertSame(2, $pool->count());
        self::assertSame(0, $flushResult->flushedRecordCount());
        self::assertSame(2, $flushResult->retainedRecordCount());
        self::assertSame(0, $flushResult->droppedRecordCount());
    }

    /**
     * Test that a transactional throw flush raises and retains the whole
     * rolled-back table along with subsequent tables.
     *
     * @return void
     */
    public function testTransactionalThrowRollsBackAndRetainsWholeTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW, transactional: true);

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        try {
            $pool->flush();
            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException) {
            // Exception intentionally caught to inspect buffer state below
        }

        self::assertSame(0, DB::table('test_unique')->count());
        self::assertSame(3, $pool->count());
    }

    /**
     * Test that the collect strategy reports record-level counts for a
     * partially failing flush.
     *
     * @return void
     */
    public function testCollectStrategyReportsRecordLevelCounts(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'a']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('test_records', ['name' => 'baz', 'value' => 'qux']);

        $flushResult = $pool->flush();

        self::assertSame(2, $flushResult->flushedRecordCount());
        self::assertSame(1, $flushResult->failedRecordCount());
        self::assertSame(1, $flushResult->retainedRecordCount());
        self::assertSame(0, $flushResult->droppedRecordCount());
    }

    /**
     * Test that the throw strategy reports record-level counts covering both
     * the failed chunk and the unprocessed records.
     *
     * @return void
     */
    public function testThrowStrategyReportsRecordLevelCounts(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('nonexistent_table', ['col' => 'a']);
        $pool->add('nonexistent_table', ['col' => 'b']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

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
     * Test that the log strategy reports the failed records as dropped rather
     * than retained.
     *
     * @return void
     */
    public function testLogStrategyReportsDroppedRecordCounts(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->once()->withAnyArgs();

        $pool->add('nonexistent_table', ['col' => 'a']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $pool->flush();

        self::assertSame(1, $flushResult->flushedRecordCount());
        self::assertSame(1, $flushResult->failedRecordCount());
        self::assertSame(0, $flushResult->retainedRecordCount());
        self::assertSame(1, $flushResult->droppedRecordCount());
    }

    /**
     * Test that a successful flush reports every table it attempted to persist,
     * so the boundary can invalidate each table's downstream per-query cache.
     *
     * @return void
     */
    public function testFlushResultReportsEveryAttemptedTable(): void
    {
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->add('test_other', ['label' => 'baz']);

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
     * Test that the partial result carried by the throw exception reports every
     * attempted table, so the boundary still invalidates the cache for tables
     * that persisted before the failure.
     *
     * @return void
     */
    public function testThrowResultReportsEveryAttemptedTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::THROW);

        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('nonexistent_table', ['col' => 'val']);

        try {
            $pool->flush();
            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException $exception) {
            self::assertSame(['test_records', 'nonexistent_table'], $exception->flushResult()->flushedTables());
        }
    }

    /**
     * Test that a transactional flush commits every chunk of a table and counts
     * each chunk and record as successfully flushed.
     *
     * @return void
     */
    public function testTransactionalFlushCommitsAndRecordsTableSuccess(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT, transactional: true);

        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('test_records', ['name' => 'baz', 'value' => 'qux']);

        $flushResult = $pool->flush();

        self::assertTrue($flushResult->isSuccessful());
        self::assertSame(2, $flushResult->successCount());
        self::assertSame(2, $flushResult->flushedRecordCount());
        self::assertSame(2, DB::table('test_records')->count());
        self::assertSame(0, $pool->count());
    }

    /**
     * Test that a transactional log flush logs the rolled-back table and drops
     * its records rather than retaining them.
     *
     * @return void
     */
    public function testTransactionalLogFlushLogsAndDropsRolledBackTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::LOG, transactional: true);

        Log::shouldReceive('error')->once()->withAnyArgs();

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);

        $flushResult = $pool->flush();

        self::assertFalse($flushResult->isSuccessful());
        self::assertSame(0, DB::table('test_unique')->count());
        self::assertTrue($pool->isEmpty());
        self::assertSame(2, $flushResult->droppedRecordCount());
        self::assertSame(0, $flushResult->retainedRecordCount());
    }

    /**
     * Test that a successful flush invalidates the per-query cache for each
     * table it persisted, so a Cacheable repository re-reads the committed rows
     * rather than serving a stale collection.
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

        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->flush();

        self::assertNull($this->cacheStore('test_records')->fetch('stale-query-hash'));
    }

    /**
     * Test that the auto-flush triggered when the pool limit is reached
     * invalidates the per-query cache, so a buffer that drains mid-request does
     * not leave a Cacheable repository serving stale rows.
     *
     * @return void
     */
    public function testAutoFlushAtPoolLimitInvalidatesTheQueryCache(): void
    {
        Config::set('cache.default', 'array');
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', true);

        $warm = $this->cacheStore('test_records');
        $warm->put('stale-query-hash', collect(['stale']), 60);

        $pool = new WritePool(chunkSize: 500, poolLimit: 1);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        self::assertSame(1, DB::table('test_records')->count());
        self::assertNull($this->cacheStore('test_records')->fetch('stale-query-hash'));
    }

    /**
     * Test that a flush invalidates the per-query cache by default when the
     * config key is absent, so the safe default keeps cached collections in
     * step with the committed rows.
     *
     * @return void
     */
    public function testFlushInvalidatesTheQueryCacheByDefaultWhenConfigKeyAbsent(): void
    {
        Config::set('cache.default', 'array');

        $deferred = (array) Config::get('api-toolkit.deferred_writes');
        unset($deferred['invalidate_query_cache']);
        Config::set('api-toolkit.deferred_writes', $deferred);

        $warm = $this->cacheStore('test_records');
        $warm->put('stale-query-hash', collect(['stale']), 60);

        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->flush();

        self::assertNull($this->cacheStore('test_records')->fetch('stale-query-hash'));
    }

    /**
     * Test that a flush leaves the per-query cache untouched when invalidation
     * is disabled by config.
     *
     * @return void
     */
    public function testFlushDoesNotInvalidateTheQueryCacheWhenDisabled(): void
    {
        Config::set('cache.default', 'array');
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', false);

        $warm = $this->cacheStore('test_records');
        $warm->put('stale-query-hash', collect(['stale']), 60);

        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $this->pool->flush();

        self::assertNotNull($this->cacheStore('test_records')->fetch('stale-query-hash'));
    }

    /**
     * Test that a flush that throws still invalidates the per-query cache for
     * the tables it persisted before the failing table, so a partial commit
     * does not leave stale cached collections behind.
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
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('nonexistent_table', ['col' => 'val']);

        try {
            $pool->flush();
            self::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException) {
            // Expected: the second table fails under the throw strategy.
        }

        self::assertNull($this->cacheStore('test_records')->fetch('stale-query-hash'));
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
