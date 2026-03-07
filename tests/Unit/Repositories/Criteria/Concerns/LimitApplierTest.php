<?php

namespace Tests\Unit\Repositories\Criteria\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\LimitApplier;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the LimitApplier concern.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LimitApplier::class)]
class LimitApplierTest extends TestCase
{
    /**
     * Test that a null limit returns the query unmodified.
     *
     * @return void
     */
    public function testApplyWithNullLimitReturnsUnmodifiedQuery(): void
    {
        $applier = new LimitApplier;
        $query   = (new User)->newQuery();

        $result = $applier->apply($query, null);

        static::assertNull($result->getQuery()->limit);
    }

    /**
     * Test that an integer limit is applied to the query.
     *
     * @return void
     */
    public function testApplyWithIntegerLimitAppliesLimit(): void
    {
        $applier = new LimitApplier;
        $query   = (new User)->newQuery();

        $result = $applier->apply($query, 5);

        static::assertSame(5, $result->getQuery()->limit);
    }

    /**
     * Test that a zero limit is applied to the query.
     *
     * @return void
     */
    public function testApplyWithZeroLimitAppliesZeroLimit(): void
    {
        $applier = new LimitApplier;
        $query   = (new User)->newQuery();

        $result = $applier->apply($query, 0);

        static::assertSame(0, $result->getQuery()->limit);
    }
}
