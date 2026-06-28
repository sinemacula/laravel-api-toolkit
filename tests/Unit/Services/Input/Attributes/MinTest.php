<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Input\Attributes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Input\Attributes\Min;

/**
 * Tests for the Min validation attribute.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Min::class)]
final class MinTest extends TestCase
{
    /**
     * Test that toRules interpolates the value into the min rule fragment.
     *
     * @return void
     */
    public function testToRulesInterpolatesValue(): void
    {
        self::assertSame(['min:128'], (new Min(128))->toRules());
    }

    /**
     * Test that toRules handles zero and boundary values correctly.
     *
     * @return void
     */
    public function testToRulesHandlesZeroAndBoundaries(): void
    {
        self::assertSame(['min:0'], (new Min(0))->toRules());
        self::assertSame(['min:1'], (new Min(1))->toRules());
    }
}
