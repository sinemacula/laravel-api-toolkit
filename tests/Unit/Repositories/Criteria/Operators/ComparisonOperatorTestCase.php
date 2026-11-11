<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Shared cases for the operators that compile to a single basic where clause.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
abstract class ComparisonOperatorTestCase extends TestCase
{
    /**
     * Test that apply adds an and-joined basic where clause.
     *
     * @return void
     */
    public function testApplyAddsBasicWhereClause(): void
    {
        $this->assertAppliedWhere(FilterContext::root(), 10, 'and');
    }

    /**
     * Test that apply in an $or context uses orWhere.
     *
     * @return void
     */
    public function testApplyInOrContextUsesOrWhere(): void
    {
        $this->assertAppliedWhere(FilterContext::nested('$or'), 'Alice', 'or');
    }

    /**
     * Create the operator under test.
     *
     * @return \SineMacula\ApiToolkit\Contracts\FilterOperator
     */
    abstract protected function operator(): FilterOperator;

    /**
     * Return the SQL comparison the operator emits.
     *
     * @return string
     */
    abstract protected function sqlOperator(): string;

    /**
     * Apply the operator in the given context and assert the single where it
     * adds.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @param  int|string  $value
     * @param  string  $boolean
     * @return void
     */
    private function assertAppliedWhere(FilterContext $context, int|string $value, string $boolean): void
    {
        $query = (new User)->newQuery();

        $this->operator()->apply($query, 'name', $value, $context);

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('Basic', $wheres[0]['type']);
        static::assertSame('name', $wheres[0]['column']);
        static::assertSame($this->sqlOperator(), $wheres[0]['operator']);
        static::assertSame($value, $wheres[0]['value']);
        static::assertSame($boolean, $wheres[0]['boolean']);
    }
}
