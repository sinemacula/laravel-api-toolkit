<?php

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotEqualOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the NotEqualOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NotEqualOperator::class)]
class NotEqualOperatorTest extends TestCase
{
    /**
     * Test that apply adds a where clause with the not-equals operator.
     *
     * @return void
     */
    public function testApplyAddsWhereClauseWithNotEqualsOperator(): void
    {
        $operator = new NotEqualOperator;
        $query    = (new User)->newQuery();

        $operator->apply($query, 'status', 'inactive', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('Basic', $wheres[0]['type']);
        static::assertSame('status', $wheres[0]['column']);
        static::assertSame('<>', $wheres[0]['operator']);
        static::assertSame('inactive', $wheres[0]['value']);
        static::assertSame('and', $wheres[0]['boolean']);
    }

    /**
     * Test that apply in an $or context uses orWhere.
     *
     * @return void
     */
    public function testNotEqualApplyInOrContextUsesOrWhere(): void
    {
        $operator = new NotEqualOperator;
        $query    = (new User)->newQuery();
        $context  = FilterContext::nested('$or', FilterContext::root());

        $operator->apply($query, 'status', 'banned', $context);

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('Basic', $wheres[0]['type']);
        static::assertSame('status', $wheres[0]['column']);
        static::assertSame('<>', $wheres[0]['operator']);
        static::assertSame('banned', $wheres[0]['value']);
        static::assertSame('or', $wheres[0]['boolean']);
    }
}
