<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\GreaterThanOrEqualOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the GreaterThanOrEqualOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(GreaterThanOrEqualOperator::class)]
final class GreaterThanOrEqualOperatorTest extends TestCase
{
    /**
     * Test that apply adds a where clause with greater than or equal operator.
     *
     * @return void
     */
    public function testApplyAddsWhereClauseWithGreaterThanOrEqualOperator(): void
    {
        $query    = (new User)->newQuery();
        $operator = new GreaterThanOrEqualOperator;

        $operator->apply($query, 'age', 18, FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame('Basic', $wheres[0]['type']);
        self::assertSame('age', $wheres[0]['column']);
        self::assertSame('>=', $wheres[0]['operator']);
        self::assertSame(18, $wheres[0]['value']);
        self::assertSame('and', $wheres[0]['boolean']);
    }

    /**
     * Test that apply in or context uses orWhere.
     *
     * @return void
     */
    public function testGreaterThanOrEqualApplyInOrContextUsesOrWhere(): void
    {
        $query    = (new User)->newQuery();
        $operator = new GreaterThanOrEqualOperator;
        $context  = FilterContext::nested('$or');

        $operator->apply($query, 'age', 18, $context);

        $wheres = $query->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame('Basic', $wheres[0]['type']);
        self::assertSame('age', $wheres[0]['column']);
        self::assertSame('>=', $wheres[0]['operator']);
        self::assertSame(18, $wheres[0]['value']);
        self::assertSame('or', $wheres[0]['boolean']);
    }
}
