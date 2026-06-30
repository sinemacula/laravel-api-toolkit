<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;

/**
 * Tests for the FilterContext value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterContext::class)]
final class FilterContextTest extends TestCase
{
    /**
     * Test that root returns a null logical operator.
     *
     * @return void
     */
    public function testRootReturnsNullOperator(): void
    {
        self::assertNull(FilterContext::root()->getLogicalOperator());
    }

    /**
     * Test that nested sets the logical operator.
     *
     * @return void
     */
    public function testNestedSetsOperator(): void
    {
        $nested = FilterContext::nested('$and');

        self::assertSame('$and', $nested->getLogicalOperator());
    }
}
