<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use SineMacula\ApiToolkit\Contracts\FilterOperator;
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
        $this->assertAppliedWhere(10);
    }

    /**
     * Test that apply adds a string comparison the same way.
     *
     * @return void
     */
    public function testApplyAddsAStringComparison(): void
    {
        $this->assertAppliedWhere('Alice');
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
     * Apply the operator and assert the single and-joined where it adds.
     *
     * @param  int|string  $value
     * @return void
     */
    private function assertAppliedWhere(int|string $value): void
    {
        $query = (new User)->newQuery();

        $this->operator()->apply($query, 'name', $value);

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('Basic', $wheres[0]['type']);
        static::assertSame('name', $wheres[0]['column']);
        static::assertSame($this->sqlOperator(), $wheres[0]['operator']);
        static::assertSame($value, $wheres[0]['value']);
        static::assertSame('and', $wheres[0]['boolean']);
    }
}
