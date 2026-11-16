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
     * Test that recordSuccess tallies both the chunk and record counts.
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
     * Test that recordFailure tallies failures and keys the detail by
     * connection, then table.
     *
     * @return void
     */
    public function testRecordFailureCapturesDetailKeyedByConnectionAndTable(): void
    {
        $accumulator = new WritePoolFlushAccumulator;
        $exception   = new \RuntimeException('insert failed');

        $accumulator->recordFailure('main', 'orders', [['id' => 1], ['id' => 2]], $exception);
        $accumulator->recordFailure('audit', 'orders', [['id' => 3]], $exception);

        $result = $accumulator->toResult(FlushStrategy::COLLECT);

        self::assertSame(2, $result->failureCount());
        self::assertSame(3, $result->failedRecordCount());
        self::assertSame([
            'main' => [
                'orders' => [
                    ['records' => [['id' => 1], ['id' => 2]], 'exception' => 'insert failed', 'exception_class' => \RuntimeException::class],
                ],
            ],
            'audit' => [
                'orders' => [
                    ['records' => [['id' => 3]], 'exception' => 'insert failed', 'exception_class' => \RuntimeException::class],
                ],
            ],
        ], $result->failures());
    }

    /**
     * Test that recordFailure accumulates failed records across chunks.
     *
     * @return void
     */
    public function testRecordFailureAccumulatesFailedRecordCountAcrossChunks(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        $accumulator->recordFailure('main', 'orders', [['id' => 1], ['id' => 2]], new \RuntimeException);
        $accumulator->recordFailure('main', 'orders', [['id' => 3], ['id' => 4], ['id' => 5]], new \RuntimeException);

        $result = $accumulator->toResult(FlushStrategy::COLLECT);

        self::assertSame(2, $result->failureCount());
        self::assertSame(5, $result->failedRecordCount());
    }

    /**
     * Test that retain merges retained records per connection and table.
     *
     * @return void
     */
    public function testRetainMergesRecordsPerTable(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        $accumulator->retain('main', 'orders', [['id' => 1]]);
        $accumulator->retain('main', 'orders', [['id' => 2]]);
        $accumulator->retain('main', 'payments', [['id' => 9]]);
        $accumulator->retain('audit', 'orders', [['id' => 4]]);

        self::assertSame([
            'main' => [
                'orders'   => [['id' => 1], ['id' => 2]],
                'payments' => [['id' => 9]],
            ],
            'audit' => [
                'orders' => [['id' => 4]],
            ],
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
     * Test that toResult retains failed records under the collect strategy.
     *
     * @return void
     */
    public function testToResultRetainsFailedRecordsForCollectStrategy(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        $accumulator->recordFailure('main', 'orders', [['id' => 1], ['id' => 2]], new \RuntimeException);

        $result = $accumulator->toResult(FlushStrategy::COLLECT, ['orders', 'payments']);

        self::assertSame(2, $result->retainedRecordCount());
        self::assertSame(0, $result->droppedRecordCount());
        self::assertSame(['orders', 'payments'], $result->flushedTables());
    }

    /**
     * Test that toResult drops failed records under the log strategy.
     *
     * @return void
     */
    public function testToResultDropsFailedRecordsForLogStrategy(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        $accumulator->recordFailure('main', 'orders', [['id' => 1], ['id' => 2]], new \RuntimeException);

        $result = $accumulator->toResult(FlushStrategy::LOG);

        self::assertSame(0, $result->retainedRecordCount());
        self::assertSame(2, $result->droppedRecordCount());
        self::assertSame([], $result->flushedTables());
    }

    /**
     * Test that toThrowResult carries the retained count and drops nothing.
     *
     * @return void
     */
    public function testToThrowResultUsesExplicitRetainedCount(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        $accumulator->recordSuccess([['id' => 1]]);
        $accumulator->recordFailure('main', 'orders', [['id' => 2]], new \RuntimeException);

        $result = $accumulator->toThrowResult(5, ['orders', 'payments']);

        self::assertSame(1, $result->successCount());
        self::assertSame(1, $result->failureCount());
        self::assertSame(1, $result->flushedRecordCount());
        self::assertSame(1, $result->failedRecordCount());
        self::assertSame(5, $result->retainedRecordCount());
        self::assertSame(0, $result->droppedRecordCount());
        self::assertSame(['orders', 'payments'], $result->flushedTables());
    }

    /**
     * Test that the result keeps the first recorded failure as its exception.
     *
     * @return void
     */
    public function testResultRetainsTheFirstRecordedFailure(): void
    {
        $accumulator = new WritePoolFlushAccumulator;
        $first       = new \RuntimeException('first');

        $accumulator->recordFailure('main', 'orders', [['id' => 1]], $first);
        $accumulator->recordFailure('main', 'payments', [['id' => 2]], new \RuntimeException('second'));

        self::assertSame($first, $accumulator->toResult(FlushStrategy::COLLECT)->firstException());
    }

    /**
     * Test that the result carries no underlying exception when nothing failed.
     *
     * @return void
     */
    public function testResultHasNoFirstExceptionWithoutFailures(): void
    {
        $accumulator = new WritePoolFlushAccumulator;

        $accumulator->recordSuccess([['id' => 1]]);

        self::assertNull($accumulator->toResult(FlushStrategy::COLLECT)->firstException());
    }

    /**
     * Test that the throw result also carries the first recorded failure.
     *
     * @return void
     */
    public function testToThrowResultCarriesTheFirstRecordedFailure(): void
    {
        $accumulator = new WritePoolFlushAccumulator;
        $first       = new \RuntimeException('first');

        $accumulator->recordFailure('main', 'orders', [['id' => 1]], $first);

        self::assertSame($first, $accumulator->toThrowResult(1)->firstException());
    }
}
