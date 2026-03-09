<?php

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\GreaterThanOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the GreaterThanOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(GreaterThanOperator::class)]
class GreaterThanOperatorTest extends TestCase
{
    /**
     * Test that apply adds a where clause with the greater-than operator.
     *
     * @return void
     */
    public function testApplyAddsWhereClauseWithGreaterThanOperator(): void
    {
        $operator = new GreaterThanOperator;
        $query    = (new User)->newQuery();

        $operator->apply($query, 'id', 10, FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('Basic', $wheres[0]['type']);
        static::assertSame('id', $wheres[0]['column']);
        static::assertSame('>', $wheres[0]['operator']);
        static::assertSame(10, $wheres[0]['value']);
        static::assertSame('and', $wheres[0]['boolean']);
    }

    /**
     * Test that apply in an $or context uses orWhere.
     *
     * @return void
     */
    public function testGreaterThanApplyInOrContextUsesOrWhere(): void
    {
        $operator = new GreaterThanOperator;
        $query    = (new User)->newQuery();
        $context  = FilterContext::nested('$or', FilterContext::root());

        $operator->apply($query, 'id', 5, $context);

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('Basic', $wheres[0]['type']);
        static::assertSame('id', $wheres[0]['column']);
        static::assertSame('>', $wheres[0]['operator']);
        static::assertSame(5, $wheres[0]['value']);
        static::assertSame('or', $wheres[0]['boolean']);
    }
}
