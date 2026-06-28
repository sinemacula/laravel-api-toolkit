<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Input\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Input\Attributes\Max;

/**
 * Tests for the Max validation attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Max::class)]
final class MaxTest extends TestCase
{
    /**
     * Test that toRules interpolates the value into the max rule fragment.
     *
     * @return void
     */
    public function testToRulesInterpolatesValue(): void
    {
        self::assertSame(['max:255'], (new Max(255))->toRules());
    }

    /**
     * Test that toRules handles zero and boundary values correctly.
     *
     * @return void
     */
    public function testToRulesHandlesZeroAndBoundaries(): void
    {
        self::assertSame(['max:0'], (new Max(0))->toRules());
        self::assertSame(['max:1'], (new Max(1))->toRules());
    }
}
