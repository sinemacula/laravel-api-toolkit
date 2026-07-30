<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\InOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the InOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(InOperator::class)]
final class InOperatorTest extends TestCase
{
    /**
     * Test that apply uses whereIn.
     *
     * @return void
     */
    public function testApplyUsesWhereIn(): void
    {
        $query    = (new User)->newQuery();
        $operator = new InOperator;

        $operator->apply($query, 'status', ['active', 'pending'], FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame('In', $wheres[0]['type']);
        self::assertSame('status', $wheres[0]['column']);
        self::assertSame(['active', 'pending'], $wheres[0]['values']);
    }

    /**
     * Test that in operator casts scalar to array.
     *
     * @return void
     */
    public function testInOperatorCastsScalarToArray(): void
    {
        $query    = (new User)->newQuery();
        $operator = new InOperator;

        $operator->apply($query, 'status', 'active', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame('In', $wheres[0]['type']);
        self::assertSame('status', $wheres[0]['column']);
        self::assertSame(['active'], $wheres[0]['values']);
    }

    /**
     * Test that apply honours the OR boolean of the current filter context so
     * an $in branch inside an $or group is combined with OR rather than AND.
     *
     * @return void
     */
    public function testInApplyInOrContextUsesOrBoolean(): void
    {
        $query    = (new User)->newQuery();
        $operator = new InOperator;

        $query->where('name', 'Alice');

        $operator->apply($query, 'status', ['active'], FilterContext::nested('$or'));

        $wheres = $query->getQuery()->wheres;

        self::assertCount(2, $wheres);
        self::assertSame('In', $wheres[1]['type']);
        self::assertSame('or', $wheres[1]['boolean']);
    }
}
