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
     * Test that a chunk size below one flushes one record per insert.
     *
     * @return void
     */
    public function testFlushChunksOneRecordPerInsertWhenTheChunkSizeIsBelowOne(): void
    {
        $pool = new WritePool(chunkSize: 0, poolLimit: 10000);

        $pool->add('test_records', ['name' => 'name_0', 'value' => 'value_0']);
        $pool->add('test_records', ['name' => 'name_1', 'value' => 'value_1']);

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

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

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

        $this->pool->add('nonexistent_table', ['col' => 'val']);

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

        $this->pool->add('nonexistent_table', ['col' => 'val']);
        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        $flushResult = $this->pool->flush();

        self::assertFalse($flushResult->isSuccessful());
        self::assertSame(0, $flushResult->droppedRecordCount());
        self::assertSame(1, $flushResult->retainedRecordCount());
        self::assertSame(1, $this->pool->count());
        self::assertInstanceOf(QueryException::class, $flushResult->firstException());
        self::assertSame($flushResult->failures()['nonexistent_table'][0]['exception'], $flushResult->firstException()->getMessage());
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
     * Test that a LOG flush returns failure details keyed by the failing table.
     *
     * @return void
     */
    public function testFlushWithLogStrategyReturnsResultWithFailureDetails(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->once()->withAnyArgs();

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
                'WritePool flush failed for table [nonexistent_table]',
                \Mockery::on(fn (array $context): bool => array_keys($context) === ['table', 'chunk_size', 'error', 'exception']
                    && $context['table']                                       === 'nonexistent_table'
                    && $context['chunk_size']                                  === 1
                    && $context['exception'] instanceof QueryException
                    && $context['error'] === $context['exception']->getMessage()),
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
     * Test that a THROW flush keeps failed and unprocessed records buffered.
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

        $pool->add('test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('test_records', ['name' => 'b', 'value' => '2']);
        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_other', ['label' => 'baz']);

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
     * Test that a COLLECT flush continues processing after a chunk failure.
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
     * Test that a COLLECT flush retains failed records in the buffer.
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
     * Test that a COLLECT flush drops successful records and keeps failed ones.
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
     * Test that a COLLECT flush reports failures for every failing table.
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
     * Test that a per-call flush strategy overrides the instance default.
     *
     * @return void
     */
    public function testFlushStrategyOverridePerCall(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('warning')->never();

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

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
        $pool = new WritePool(chunkSize: 500, poolLimit: 2, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->once()->withAnyArgs();

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        self::assertSame(0, $pool->count());
    }

    /**
     * Test that a memory-pressure auto-flush under THROW throws out of add().
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
     * Test that auto-flush uses COLLECT when configured with COLLECT.
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
        $pool = new WritePool(chunkSize: 500, poolLimit: 2);

        $pool->add('test_records', ['name' => 'a', 'value' => '1']);
        $pool->add('test_records', ['name' => 'b', 'value' => '2']);

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
     * Test that a LOG flush clears the buffer regardless of failures.
     *
     * @return void
     */
    public function testFlushWithLogStrategyClearsBufferRegardlessOfFailures(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->once()->withAnyArgs();

        $pool->add('nonexistent_table', ['col' => 'val']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

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

        $pool->add('nonexistent_table', ['col' => 'a']);
        $pool->add('nonexistent_table', ['col' => 'b']);

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

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'beta']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

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

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);

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

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);

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

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);

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

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

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

        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_other', ['label' => 'baz']);

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
     * Test that THROW reports record counts for failed and unprocessed records.
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
     * Test that LOG reports failed records as dropped.
     *
     * @return void
     */
    public function testLogStrategyReportsDroppedRecordCounts(): void
    {
        $pool = new WritePool(chunkSize: 500, poolLimit: 10000, strategy: FlushStrategy::LOG);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->once()->withAnyArgs();

        $pool->add('nonexistent_table', ['col' => 'a']);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

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
     * Test that the THROW partial result reports every attempted table.
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
     * Test that a transactional flush commits and counts every chunk.
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
     * Test that a transactional LOG flush logs and drops a rolled-back table.
     *
     * @return void
     */
    public function testTransactionalLogFlushLogsAndDropsRolledBackTable(): void
    {
        $pool = new WritePool(chunkSize: 1, poolLimit: 10000, strategy: FlushStrategy::LOG, transactional: true);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('warning')->once()->withAnyArgs();

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

        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
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

        $pool = new WritePool(chunkSize: 500, poolLimit: 1);
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);

        self::assertSame(1, DB::table('test_records')->count());
        self::assertNull($this->cacheStore('test_records')->fetch('stale-query-hash'));
    }

    /**
     * Test that a flush invalidates the cache when the config key is absent.
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

        $this->pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
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
        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('nonexistent_table', ['col' => 'val']);

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

        $pool->add('nonexistent_table', ['col' => 'a']);
        $pool->add('nonexistent_table', ['col' => 'b']);
        $pool->add('another_missing_table', ['col' => 'c']);

        $flushResult = $pool->flush();

        self::assertFalse($flushResult->isSuccessful());
        self::assertSame(0, $flushResult->successCount());
        self::assertSame(2, $flushResult->failureCount());
        self::assertSame(0, $flushResult->flushedRecordCount());
        self::assertSame(3, $flushResult->failedRecordCount());
        self::assertSame(3, $flushResult->retainedRecordCount());
        self::assertSame(0, $flushResult->droppedRecordCount());
        self::assertArrayHasKey('nonexistent_table', $flushResult->failures());
        self::assertArrayHasKey('another_missing_table', $flushResult->failures());
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

        $pool->add('test_deferred_retry', ['label' => 'first']);
        $pool->add('test_deferred_retry', ['label' => 'second']);

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

        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'beta']);
        $pool->add('test_unique', ['name' => 'gamma']);
        $pool->add('test_unique', ['name' => 'gamma']);
        $pool->add('test_unique', ['name' => 'delta']);
        $pool->add('test_unique', ['name' => 'epsilon']);

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

        $pool->add('test_records', ['name' => 'foo', 'value' => 'bar']);
        $pool->add('test_records', ['name' => 'baz', 'value' => 'qux']);
        $pool->add('test_unique', ['name' => 'alpha']);
        $pool->add('test_unique', ['name' => 'alpha']);

        $flushResult = $pool->flush();

        self::assertSame(2, DB::table('test_records')->count());
        self::assertSame(0, DB::table('test_unique')->count());
        self::assertSame(2, $flushResult->flushedRecordCount());
        self::assertSame(2, $flushResult->retainedRecordCount());
        self::assertSame(0, $flushResult->droppedRecordCount());
        self::assertSame(2, $pool->count());
        self::assertArrayHasKey('test_unique', $flushResult->failures());
        self::assertArrayNotHasKey('test_records', $flushResult->failures());
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
