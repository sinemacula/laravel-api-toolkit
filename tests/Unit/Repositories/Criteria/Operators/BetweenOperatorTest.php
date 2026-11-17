<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\FilterValueShape;
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
final class BetweenOperatorTest extends TestCase
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

        $operator->apply($query, 'age', [18, 65]);

        $wheres = $query->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame('between', $wheres[0]['type']);
        self::assertSame('age', $wheres[0]['column']);
    }

    /**
     * Test that the operator takes a low and a high bound.
     *
     * @return void
     */
    public function testValueShapeIsBounds(): void
    {
        self::assertSame(FilterValueShape::BOUNDS, (new BetweenOperator)->valueShape());
    }

    /**
     * Test that apply joins the range to an earlier clause with AND.
     *
     * @return void
     */
    public function testBetweenApplyJoinsAnEarlierClauseWithAnd(): void
    {
        $query    = (new User)->newQuery();
        $operator = new BetweenOperator;

        $query->where('name', 'Alice');

        $operator->apply($query, 'age', [18, 65]);

        $wheres = $query->getQuery()->wheres;

        self::assertCount(2, $wheres);
        self::assertSame('between', $wheres[1]['type']);
        self::assertSame('and', $wheres[1]['boolean']);
    }
}
