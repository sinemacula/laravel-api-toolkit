<?php

declare(strict_types = 1);

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
final class NarrowingDecisionTest extends TestCase
{
    /**
     * Test that narrow carries the given columns and no reason.
     *
     * @return void
     */
    public function testNarrowCarriesColumnsAndNoReason(): void
    {
        $decision = NarrowingDecision::narrow(['a', 'b']);

        self::assertTrue($decision->shouldNarrow());
        self::assertSame(['a', 'b'], $decision->columns());
        self::assertNull($decision->reason());
    }

    /**
     * Test that narrow reindexes non-sequential column keys.
     *
     * @return void
     */
    public function testNarrowReindexesColumns(): void
    {
        $decision = NarrowingDecision::narrow([2 => 'a', 5 => 'b']);

        self::assertSame(['a', 'b'], $decision->columns());
    }

    /**
     * Test that fallback carries the given reason and returns no columns.
     *
     * @return void
     */
    public function testFallbackCarriesReasonAndNoColumns(): void
    {
        $decision = NarrowingDecision::fallback('full_label');

        self::assertFalse($decision->shouldNarrow());
        self::assertSame([], $decision->columns());
        self::assertSame('full_label', $decision->reason());
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

        self::assertFalse($decision->shouldNarrow());
        self::assertNull($decision->reason());
    }
}
