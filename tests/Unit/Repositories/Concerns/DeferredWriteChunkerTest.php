<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Repositories\Concerns\DeferredWriteChunker;

/**
 * Tests for the DeferredWriteChunker.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DeferredWriteChunker::class)]
final class DeferredWriteChunkerTest extends TestCase
{
    /**
     * Test that no rows produce no chunks.
     *
     * @return void
     */
    public function testChunkReturnsNoChunksForNoRows(): void
    {
        self::assertSame([], (new DeferredWriteChunker(2))->chunk([]));
    }

    /**
     * Test that rows of one column set chunk by the chunk size.
     *
     * @return void
     */
    public function testChunkSplitsOneColumnSetByTheChunkSize(): void
    {
        self::assertSame([
            [['a' => 1], ['a' => 2]],
            [['a' => 3]],
        ], (new DeferredWriteChunker(2))->chunk([['a' => 1], ['a' => 2], ['a' => 3]]));
    }

    /**
     * Test that a chunk size below one chunks one row at a time.
     *
     * @return void
     */
    public function testChunkUsesOneRowPerChunkWhenTheChunkSizeIsBelowOne(): void
    {
        self::assertSame([[['a' => 1]], [['a' => 2]]], (new DeferredWriteChunker(0))->chunk([['a' => 1], ['a' => 2]]));
    }

    /**
     * Test that key order does not split a run.
     *
     * @return void
     */
    public function testChunkKeepsRowsOfOneColumnSetInAnyKeyOrderTogether(): void
    {
        self::assertSame([
            [['a' => 1, 'b' => 2], ['b' => 4, 'a' => 3]],
        ], (new DeferredWriteChunker(10))->chunk([['a' => 1, 'b' => 2], ['b' => 4, 'a' => 3]]));
    }

    /**
     * Test that rows sharing their first column but not the rest split into
     * separate runs.
     *
     * @return void
     */
    public function testChunkSplitsRowsThatDifferBeyondTheFirstColumn(): void
    {
        self::assertSame([
            [['a' => 1, 'b' => 2]],
            [['a' => 3, 'c' => 4]],
        ], (new DeferredWriteChunker(10))->chunk([['a' => 1, 'b' => 2], ['a' => 3, 'c' => 4]]));
    }

    /**
     * Test that consecutive runs of different column sets chunk on their own
     * and keep the insertion order.
     *
     * @return void
     */
    public function testChunkSplitsConsecutiveRunsKeepingTheOrder(): void
    {
        self::assertSame([
            [['a' => 1], ['a' => 2]],
            [['a' => 3]],
            [['b' => 4]],
            [['a' => 5]],
        ], (new DeferredWriteChunker(2))->chunk([['a' => 1], ['a' => 2], ['a' => 3], ['b' => 4], ['a' => 5]]));
    }
}
