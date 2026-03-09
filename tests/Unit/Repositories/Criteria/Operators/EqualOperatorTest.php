<?php

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\EqualOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the EqualOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EqualOperator::class)]
class EqualOperatorTest extends TestCase
{
    /**
     * Test that apply adds a where clause with the equals operator.
     *
     * @return void
     */
    public function testApplyAddsWhereClauseWithEqualsOperator(): void
    {
        $operator = new EqualOperator;
        $query    = (new User)->newQuery();

        $operator->apply($query, 'name', 'Alice', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('Basic', $wheres[0]['type']);
        static::assertSame('name', $wheres[0]['column']);
        static::assertSame('=', $wheres[0]['operator']);
        static::assertSame('Alice', $wheres[0]['value']);
        static::assertSame('and', $wheres[0]['boolean']);
    }

    /**
     * Test that apply in an $or context uses orWhere.
     *
     * @return void
     */
    public function testApplyInOrContextUsesOrWhere(): void
    {
        $operator = new EqualOperator;
        $query    = (new User)->newQuery();
        $context  = FilterContext::nested('$or', FilterContext::root());

        $operator->apply($query, 'name', 'Bob', $context);

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('Basic', $wheres[0]['type']);
        static::assertSame('name', $wheres[0]['column']);
        static::assertSame('=', $wheres[0]['operator']);
        static::assertSame('Bob', $wheres[0]['value']);
        static::assertSame('or', $wheres[0]['boolean']);
    }
}
