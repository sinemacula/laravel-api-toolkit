<?php

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\NarrowingDecision;

/**
 * Tests for the NarrowingDecision value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NarrowingDecision::class)]
class NarrowingDecisionTest extends TestCase
{
    /**
     * Test that narrow carries the given columns and no reason.
     *
     * @return void
     */
    public function testNarrowCarriesColumnsAndNoReason(): void
    {
        $decision = NarrowingDecision::narrow(['a', 'b']);

        static::assertTrue($decision->shouldNarrow());
        static::assertSame(['a', 'b'], $decision->columns());
        static::assertNull($decision->reason());
    }

    /**
     * Test that narrow reindexes non-sequential column keys.
     *
     * @return void
     */
    public function testNarrowReindexesColumns(): void
    {
        $decision = NarrowingDecision::narrow([2 => 'a', 5 => 'b']);

        static::assertSame(['a', 'b'], $decision->columns());
    }

    /**
     * Test that fallback carries the given reason and returns no columns.
     *
     * @return void
     */
    public function testFallbackCarriesReasonAndNoColumns(): void
    {
        $decision = NarrowingDecision::fallback('full_label');

        static::assertFalse($decision->shouldNarrow());
        static::assertSame([], $decision->columns());
        static::assertSame('full_label', $decision->reason());
    }

    /**
     * Test that fallback without a reason has a null reason and does not
     * narrow.
     *
     * @return void
     */
    public function testFallbackWithoutReasonHasNullReason(): void
    {
        $decision = NarrowingDecision::fallback();

        static::assertFalse($decision->shouldNarrow());
        static::assertNull($decision->reason());
    }
}
