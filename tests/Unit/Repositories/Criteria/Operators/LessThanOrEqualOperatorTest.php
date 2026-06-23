<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LessThanOrEqualOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the LessThanOrEqualOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LessThanOrEqualOperator::class)]
final class LessThanOrEqualOperatorTest extends TestCase
{
    /**
     * Test that apply adds a where clause with less than or equal operator.
     *
     * @return void
     */
    public function testApplyAddsWhereClauseWithLessThanOrEqualOperator(): void
    {
        $query    = (new User)->newQuery();
        $operator = new LessThanOrEqualOperator;

        $operator->apply($query, 'age', 25, FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame('Basic', $wheres[0]['type']);
        self::assertSame('age', $wheres[0]['column']);
        self::assertSame('<=', $wheres[0]['operator']);
        self::assertSame(25, $wheres[0]['value']);
        self::assertSame('and', $wheres[0]['boolean']);
    }

    /**
     * Test that apply in or context uses orWhere.
     *
     * @return void
     */
    public function testLessThanOrEqualApplyInOrContextUsesOrWhere(): void
    {
        $query    = (new User)->newQuery();
        $operator = new LessThanOrEqualOperator;
        $context  = FilterContext::nested('$or', FilterContext::root());

        $operator->apply($query, 'age', 25, $context);

        $wheres = $query->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame('Basic', $wheres[0]['type']);
        self::assertSame('age', $wheres[0]['column']);
        self::assertSame('<=', $wheres[0]['operator']);
        self::assertSame(25, $wheres[0]['value']);
        self::assertSame('or', $wheres[0]['boolean']);
    }
}
