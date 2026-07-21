<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushAccumulator;

/**
 * Tests for the WritePoolFlushAccumulator collaborator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePoolFlushAccumulator::class)]
final class WritePoolFlushAccumulatorTest extends TestCase
{
    /**
     * Test that recordSuccess tallies both the chunk count and the record
     * count.
     *
     * @return void
     */
    public function testRecordSuccessTalliesChunkAndRecordCounts(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        $accumulator->recordSuccess([['a' => 1], ['a' => 2]]);
        $accumulator->recordSuccess([['a' => 3]]);

        $result = $accumulator->toResult(FlushStrategy::COLLECT);

        self::assertSame(2, $result->successCount());
        self::assertSame(3, $result->flushedRecordCount());
        self::assertSame(0, $result->failureCount());
    }

    /**
     * Test that recordFailure tallies the failure count and captures the
     * failure detail keyed by table name.
     *
     * @return void
     */
    public function testRecordFailureCapturesDetailKeyedByTable(): void
    {
        $accumulator = new WritePoolFlushAccumulator;
        $exception   = new \RuntimeException('insert failed');

        $accumulator->recordFailure('orders', [['id' => 1], ['id' => 2]], $exception);

        $result   = $accumulator->toResult(FlushStrategy::COLLECT);
        $failures = $result->failures();

        self::assertSame(1, $result->failureCount());
        self::assertSame(2, $result->failedRecordCount());
        self::assertArrayHasKey('orders', $failures);
        self::assertSame([['id' => 1], ['id' => 2]], $failures['orders'][0]['records']);
        self::assertSame('insert failed', $failures['orders'][0]['exception']);
        self::assertSame(\RuntimeException::class, $failures['orders'][0]['exception_class']);
    }

    /**
     * Test that recordFailure accumulates the failed record count across
     * multiple failed chunks rather than overwriting it with the latest chunk.
     *
     * @return void
     */
    public function testRecordFailureAccumulatesFailedRecordCountAcrossChunks(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        $accumulator->recordFailure('orders', [['id' => 1], ['id' => 2]], new \RuntimeException);
        $accumulator->recordFailure('orders', [['id' => 3], ['id' => 4], ['id' => 5]], new \RuntimeException);

        $result = $accumulator->toResult(FlushStrategy::COLLECT);

        self::assertSame(2, $result->failureCount());
        self::assertSame(5, $result->failedRecordCount());
    }

    /**
     * Test that retain merges retained records per table and that failedRecords
     * exposes them for retry.
     *
     * @return void
     */
    public function testRetainMergesRecordsPerTable(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        $accumulator->retain('orders', [['id' => 1]]);
        $accumulator->retain('orders', [['id' => 2]]);
        $accumulator->retain('payments', [['id' => 9]]);

        self::assertSame([
            'orders'   => [['id' => 1], ['id' => 2]],
            'payments' => [['id' => 9]],
        ], $accumulator->failedRecords());
    }

    /**
     * Test that failedRecords returns an empty array when nothing was retained.
     *
     * @return void
     */
    public function testFailedRecordsIsEmptyByDefault(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        self::assertSame([], $accumulator->failedRecords());
    }

    /**
     * Test that toResult retains failed records under the collect strategy,
     * dropping none, and reports the attempted tables.
     *
     * @return void
     */
    public function testToResultRetainsFailedRecordsForCollectStrategy(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        $accumulator->recordFailure('orders', [['id' => 1], ['id' => 2]], new \RuntimeException);

        $result = $accumulator->toResult(FlushStrategy::COLLECT, ['orders', 'payments']);

        self::assertSame(2, $result->retainedRecordCount());
        self::assertSame(0, $result->droppedRecordCount());
        self::assertSame(['orders', 'payments'], $result->flushedTables());
    }

    /**
     * Test that toResult drops failed records under the log strategy, retaining
     * none.
     *
     * @return void
     */
    public function testToResultDropsFailedRecordsForLogStrategy(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        $accumulator->recordFailure('orders', [['id' => 1], ['id' => 2]], new \RuntimeException);

        $result = $accumulator->toResult(FlushStrategy::LOG);

        self::assertSame(0, $result->retainedRecordCount());
        self::assertSame(2, $result->droppedRecordCount());
        self::assertSame([], $result->flushedTables());
    }

    /**
     * Test that toThrowResult carries the explicit retained record count and
     * drops nothing.
     *
     * @return void
     */
    public function testToThrowResultUsesExplicitRetainedCount(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        $accumulator->recordSuccess([['id' => 1]]);
        $accumulator->recordFailure('orders', [['id' => 2]], new \RuntimeException);

        $result = $accumulator->toThrowResult(5, ['orders', 'payments']);

        self::assertSame(1, $result->successCount());
        self::assertSame(1, $result->failureCount());
        self::assertSame(1, $result->flushedRecordCount());
        self::assertSame(1, $result->failedRecordCount());
        self::assertSame(5, $result->retainedRecordCount());
        self::assertSame(0, $result->droppedRecordCount());
        self::assertSame(['orders', 'payments'], $result->flushedTables());
    }
}
