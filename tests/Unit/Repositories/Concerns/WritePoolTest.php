<?php

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Exceptions\WritePoolFlushException;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushResult;
use Tests\TestCase;
use Illuminate\Database\QueryException;

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

        static::assertSame(1, $this->pool->count());
        static::assertFalse($this->pool->isEmpty());
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

        static::assertSame(2, $this->pool->count());
    }

    /**
     * Test that count returns zero when the buffer is empty.
     *
     * @return void
     */
    public function testCountReturnsZeroWhenEmpty(): void
    {
        static::assertSame(0, $this->pool->count());
    }

    /**
     * Test that isEmpty returns true when no records are buffered.
     *
     * @return void
     */
    public function testIsEmptyReturnsTrueWhenNoRecordsBuffered(): void
    {
        static::assertTrue($this->pool->isEmpty());
    }

    /**
     * Test that isEmpty returns false after add.
     *
     * @return void
     */
    public function testIsEmptyReturnsFalseAfterAdd(): void
    {
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        static::assertFalse($this->pool->isEmpty());
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

        static::assertTrue($flushResult->isSuccessful());
        static::assertSame(2, DB::table('test_records')->count());
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

        static::assertTrue($flushResult->isSuccessful());
        static::assertSame(1, DB::table('test_records')->count());
        static::assertSame(1, DB::table('test_other')->count());
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

        static::assertTrue($flushResult->isSuccessful());
        static::assertSame(0, $this->pool->count());
        static::assertTrue($this->pool->isEmpty());
    }

    /**
     * Test that flush is a no-op when the buffer is empty.
     *
     * @return void
     */
    public function testFlushIsNoOpWhenBufferIsEmpty(): void
    {
        $flushResult = $this->pool->flush();

        static::assertTrue($flushResult->isSuccessful());
        static::assertSame(0, $flushResult->totalCount());
        static::assertSame(0, $this->pool->count());
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

        static::assertSame(3, $flushResult->successCount());
        static::assertCount(3, $queries);
        static::assertSame(5, DB::table('test_records')->count());
    }

    /**
     * Test that the default strategy continues past a chunk failure
     * without logging an error and persists the healthy chunk.
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

        static::assertFalse($flushResult->isSuccessful());
        static::assertSame(1, $flushResult->failureCount());
        static::assertSame(1, DB::table('test_records')->count());
    }

    /**
     * Test that the default strategy retains failed records in the
     * buffer rather than dropping them after a partial failure.
     *
     * @return void
     */
    public function testDefaultStrategyRetainsBufferAfterPartialFailure(): void
    {
        Log::shouldReceive('error')->never();

        $this->pool->add('nonexistent_table', ['col' => 'val']);

        $this->pool->flush();

        static::assertSame(1, $this->pool->count());
        static::assertFalse($this->pool->isEmpty());
    }

    /**
     * Test that the default strategy is collect, surfacing failures
     * loudly in the result with zero dropped records.
     *
     * @return void
     */
    public function testDefaultStrategyIsCollectAndDropsNoRecords(): void
    {
        Log::shouldReceive('error')->never();

        $this->pool->add('nonexistent_table', ['col' => 'val']);
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $this->pool->flush();

        static::assertFalse($flushResult->isSuccessful());
        static::assertSame(0, $flushResult->droppedRecordCount());
        static::assertSame(1, $flushResult->retainedRecordCount());
        static::assertSame(1, $this->pool->count());
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

        static::assertSame(3, DB::table('test_records')->count());
        static::assertSame(0, $pool->count());
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

        static::assertSame(2, DB::table('test_records')->count());
        static::assertSame(0, $pool->count());
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

        static::assertSame(0, $pool->count());

        $pool->add('test_records', ['name' => 'c', 'value' => '3']);

        static::assertSame(1, $pool->count());
        static::assertSame(2, DB::table('test_records')->count());
    }

    /**
     * Test that flush with LOG strategy returns a result containing
     * failure details keyed by the failing table.
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

        static::assertFalse($flushResult->isSuccessful());
        static::assertSame(1, $flushResult->failureCount());
        static::assertSame(1, $flushResult->successCount());
        static::assertArrayHasKey('nonexistent_table', $flushResult->failures());
        static::assertSame([['col' => 'val']], $flushResult->failures()['nonexistent_table'][0]['records']);
        static::assertNotEmpty($flushResult->failures()['nonexistent_table'][0]['exception']);
        static::assertSame(QueryException::class, $flushResult->failures()['nonexistent_table'][0]['exception_class']);
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
     * Test that flush with THROW strategy throws on the first
     * chunk failure.
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
     * Test that flush with THROW strategy preserves failed and
     * unprocessed records in the buffer.
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

        static::assertFalse($pool->isEmpty());
        static::assertSame(2, $pool->count());
    }

    /**
     * Test that flush with THROW strategy removes successfully
     * inserted records from the buffer.
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

        static::assertSame(2, DB::table('test_records')->count());
        static::assertSame(2, $pool->count());
    }

    /**
     * Test that the WritePoolFlushException thrown by the THROW
     * strategy contains a partial result with correct counts.
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
            static::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException $exception) {

            $partialResult = $exception->flushResult();

            static::assertSame(1, $partialResult->successCount());
            static::assertSame(1, $partialResult->failureCount());
        }
    }

    /**
     * Test that flush with COLLECT strategy continues processing
     * after a chunk failure.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyContinuesAfterFailure(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        static::assertSame(1, DB::table('test_records')->count());
    }

    /**
     * Test that flush with COLLECT strategy retains failed records
     * in the buffer.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyRetainsFailedRecordsInBuffer(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        static::assertSame(1, $pool->count());
        static::assertFalse($pool->isEmpty());
    }

    /**
     * Test that flush with COLLECT strategy removes successful
     * records from the buffer while retaining failed records.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyRemovesSuccessfulRecordsFromBuffer(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        static::assertSame(1, DB::table('test_records')->count());
        static::assertSame(1, $pool->count());
    }

    /**
     * Test that flush with COLLECT strategy returns a result
     * containing failures for all failing tables.
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

        static::assertSame(2, $flushResult->failureCount());
        static::assertSame(1, $flushResult->successCount());
        static::assertArrayHasKey('nonexistent_table', $flushResult->failures());
        static::assertArrayHasKey('another_missing_table', $flushResult->failures());
    }

    /**
     * Test that flush accepts a per-call strategy override that
     * takes precedence over the instance default.
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

        static::assertSame(1, $pool->count());
        static::assertFalse($pool->isEmpty());
    }

    /**
     * Test that auto-flush uses the LOG strategy when the pool is
     * configured with LOG.
     *
     * @return void
     */
    public function testAutoFlushUsesLogStrategyWhenConfiguredAsLog(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->once()->withAnyArgs();

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        static::assertSame(0, $pool->count());
    }

    /**
     * Test that memory-pressure auto-flush honours the throw strategy,
     * raising a WritePoolFlushException out of add().
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
     * Test that auto-flush uses COLLECT strategy when the pool is
     * configured with COLLECT.
     *
     * @return void
     */
    public function testAutoFlushUsesCollectStrategyWhenConfiguredAsCollect(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        static::assertNotNull($pool->lastAutoFlushResult());
    }

    /**
     * Test that lastAutoFlushResult returns null before any
     * auto-flush has occurred.
     *
     * @return void
     */
    public function testLastAutoFlushResultReturnsNullBeforeAutoFlush(): void
    {
        static::assertNull($this->pool->lastAutoFlushResult());
    }

    /**
     * Test that lastAutoFlushResult returns a WritePoolFlushResult
     * instance after an auto-flush has been triggered.
     *
     * @return void
     */
    public function testLastAutoFlushResultReturnsResultAfterAutoFlush(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 2);

        $pool->add('test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('test_records', ['name' => 'b', 'value' => '2']);

        static::assertInstanceOf(WritePoolFlushResult::class, $pool->lastAutoFlushResult());
    }

    /**
     * Test that a subsequent flush after a COLLECT flush only
     * processes the retained failed records.
     *
     * @return void
     */
    public function testSubsequentFlushOnlyAttemptsRetainedRecords(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $pool->flush();

        static::assertSame(1, DB::table('test_records')->count());
        static::assertSame(1, $pool->count());

        $secondResult = $pool->flush();

        static::assertSame(0, $secondResult->successCount());
        static::assertSame(1, $secondResult->failureCount());
        static::assertSame(1, $pool->count());
    }

    /**
     * Test that flush with LOG strategy clears the buffer
     * regardless of any failures that occurred.
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

        static::assertTrue($pool->isEmpty());
        static::assertSame(0, $pool->count());
    }

    /**
     * Test that flush with COLLECT strategy accumulates multiple failed
     * chunks for the same table in the retained buffer.
     *
     * @return void
     */
    public function testFlushWithCollectStrategyAccumulatesFailedChunksForSameTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('nonexistent_table', ['col' => 'a']);
        $pool->add('nonexistent_table', ['col' => 'b']);

        $pool->flush();

        static::assertSame(2, $pool->count());
    }

    /**
     * Test that flush with THROW strategy retains exactly the failed
     * chunk, the remaining chunks of the failing table, and the
     * untouched subsequent tables.
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
            static::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException) {
            // Exception intentionally caught to inspect buffer state below
        }

        static::assertSame(1, DB::table('test_unique')->count());
        static::assertSame(0, DB::table('test_records')->count());
        static::assertSame(3, $pool->count());
    }

    /**
     * Test that a transactional flush rolls back the entire table when
     * any chunk fails, leaving none of that table's rows persisted.
     *
     * @return void
     */
    public function testTransactionalFlushRollsBackWholeTableOnChunkFailure(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT, transactional: true);

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);

        $pool->flush();

        static::assertSame(0, DB::table('test_unique')->count());
    }

    /**
     * Test that a non-transactional flush persists the healthy chunk
     * even when a later chunk in the same table fails.
     *
     * @return void
     */
    public function testNonTransactionalFlushPersistsHealthyChunkOnLaterFailure(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT);

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);

        $pool->flush();

        static::assertSame(1, DB::table('test_unique')->count());
    }

    /**
     * Test that a transactional collect flush retains every record of a
     * rolled-back table for retry, including the rows that committed
     * before the failing chunk.
     *
     * @return void
     */
    public function testTransactionalCollectRetainsWholeRolledBackTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::COLLECT, transactional: true);

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);

        $flushResult = $pool->flush();

        static::assertSame(2, $pool->count());
        static::assertSame(0, $flushResult->flushedRecordCount());
        static::assertSame(2, $flushResult->retainedRecordCount());
        static::assertSame(0, $flushResult->droppedRecordCount());
    }

    /**
     * Test that a transactional throw flush raises and retains the
     * whole rolled-back table along with subsequent tables.
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
            static::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException) {
            // Exception intentionally caught to inspect buffer state below
        }

        static::assertSame(0, DB::table('test_unique')->count());
        static::assertSame(3, $pool->count());
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

        static::assertSame(2, $flushResult->flushedRecordCount());
        static::assertSame(1, $flushResult->failedRecordCount());
        static::assertSame(1, $flushResult->retainedRecordCount());
        static::assertSame(0, $flushResult->droppedRecordCount());
    }

    /**
     * Test that the throw strategy reports record-level counts covering
     * both the failed chunk and the unprocessed records.
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
            static::fail('Expected WritePoolFlushException was not thrown');
        } catch (WritePoolFlushException $exception) {

            $partialResult = $exception->flushResult();

            static::assertSame(0, $partialResult->flushedRecordCount());
            static::assertSame(1, $partialResult->failedRecordCount());
            static::assertSame(3, $partialResult->retainedRecordCount());
            static::assertSame(0, $partialResult->droppedRecordCount());
        }
    }

    /**
     * Test that the log strategy reports the failed records as dropped
     * rather than retained.
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

        static::assertSame(1, $flushResult->flushedRecordCount());
        static::assertSame(1, $flushResult->failedRecordCount());
        static::assertSame(0, $flushResult->retainedRecordCount());
        static::assertSame(1, $flushResult->droppedRecordCount());
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
}
