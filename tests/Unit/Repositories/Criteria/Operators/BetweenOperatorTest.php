<?php

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\BetweenOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the BetweenOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(BetweenOperator::class)]
class BetweenOperatorTest extends TestCase
{
    /**
     * Test that apply uses whereBetween with two elements.
     *
     * @return void
     */
    public function testApplyUsesWhereBetweenWithTwoElements(): void
    {
        $query    = (new User)->newQuery();
        $operator = new BetweenOperator;

        $operator->apply($query, 'age', [18, 65], FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('between', $wheres[0]['type']);
        static::assertSame('age', $wheres[0]['column']);
    }

    /**
     * Test that between with single element is ignored.
     *
     * @return void
     */
    public function testBetweenWithSingleElementIsIgnored(): void
    {
        $query    = (new User)->newQuery();
        $operator = new BetweenOperator;

        $operator->apply($query, 'age', [18], FilterContext::root());

        static::assertEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that between with three elements is ignored.
     *
     * @return void
     */
    public function testBetweenWithThreeElementsIsIgnored(): void
    {
        $query    = (new User)->newQuery();
        $operator = new BetweenOperator;

        $operator->apply($query, 'age', [18, 30, 65], FilterContext::root());

        static::assertEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that between with non-array is ignored.
     *
     * @return void
     */
    public function testBetweenWithNonArrayIsIgnored(): void
    {
        $query    = (new User)->newQuery();
        $operator = new BetweenOperator;

        $operator->apply($query, 'age', 'not-an-array', FilterContext::root());

        static::assertEmpty($query->getQuery()->wheres);
    }
}
