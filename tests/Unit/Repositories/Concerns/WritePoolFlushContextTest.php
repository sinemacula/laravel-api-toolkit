<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePoolFlushContext;

/**
 * Tests for the WritePoolFlushContext value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(WritePoolFlushContext::class)]
final class WritePoolFlushContextTest extends TestCase
{
    /**
     * Test that the accessors return the values supplied to the constructor.
     *
     * @return void
     */
    public function testAccessorsReturnConstructorValues(): void
    {
        $chunks  = [[['id' => 1]], [['id' => 2]]];
        $context = new WritePoolFlushContext(FlushStrategy::COLLECT, 'orders', $chunks, ['orders', 'payments'], 0);

        self::assertSame(FlushStrategy::COLLECT, $context->strategy());
        self::assertSame('orders', $context->table());
        self::assertSame($chunks, $context->chunks());
        self::assertSame(['orders', 'payments'], $context->tables());
        self::assertSame(0, $context->tableIndex());
    }

    /**
     * Test that chunkIndex defaults to null when omitted from the constructor.
     *
     * @return void
     */
    public function testChunkIndexDefaultsToNull(): void
    {
        $context = new WritePoolFlushContext(FlushStrategy::THROW, 'orders', [], [], 0);

        self::assertNull($context->chunkIndex());
    }

    /**
     * Test that a chunk index provided to the constructor is exposed by the
     * accessor.
     *
     * @return void
     */
    public function testChunkIndexReturnsConstructorValue(): void
    {
        $context = new WritePoolFlushContext(FlushStrategy::LOG, 'orders', [], ['orders'], 2, 7);

        self::assertSame(7, $context->chunkIndex());
        self::assertSame(2, $context->tableIndex());
    }

    /**
     * Test that withChunkIndex returns a new instance carrying the given chunk
     * index while preserving every other field and leaving the original
     * untouched.
     *
     * @return void
     */
    public function testWithChunkIndexReturnsNewInstancePreservingOtherFields(): void
    {
        $chunks   = [[['id' => 1]], [['id' => 2]]];
        $original = new WritePoolFlushContext(FlushStrategy::THROW, 'orders', $chunks, ['orders', 'payments'], 1);

        $derived = $original->withChunkIndex(3);

        self::assertNotSame($original, $derived);
        self::assertNull($original->chunkIndex());
        self::assertSame(3, $derived->chunkIndex());
        self::assertSame(FlushStrategy::THROW, $derived->strategy());
        self::assertSame('orders', $derived->table());
        self::assertSame($chunks, $derived->chunks());
        self::assertSame(['orders', 'payments'], $derived->tables());
        self::assertSame(1, $derived->tableIndex());
    }
}
