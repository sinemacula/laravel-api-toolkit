<?php

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
class InOperatorTest extends TestCase
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

        static::assertCount(1, $wheres);
        static::assertSame('In', $wheres[0]['type']);
        static::assertSame('status', $wheres[0]['column']);
        static::assertSame(['active', 'pending'], $wheres[0]['values']);
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

        static::assertCount(1, $wheres);
        static::assertSame('In', $wheres[0]['type']);
        static::assertSame('status', $wheres[0]['column']);
        static::assertSame(['active'], $wheres[0]['values']);
    }
}
