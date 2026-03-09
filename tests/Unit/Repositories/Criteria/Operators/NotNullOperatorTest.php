<?php

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotNullOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the NotNullOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NotNullOperator::class)]
class NotNullOperatorTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotNullOperator */
    private NotNullOperator $operator;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = new NotNullOperator;
    }

    /**
     * Test that apply adds a whereNotNull constraint.
     *
     * @return void
     */
    public function testApplyAddsWhereNotNull(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'name', true, FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertSame('NotNull', $wheres[0]['type']);
        static::assertSame('name', $wheres[0]['column']);
    }

    /**
     * Test that apply in $or context uses orWhereNotNull.
     *
     * @return void
     */
    public function testNotNullApplyInOrContextUsesOrWhereNotNull(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'name', true, FilterContext::nested('$or', FilterContext::root()));

        $wheres = $query->getQuery()->wheres;

        static::assertSame('NotNull', $wheres[0]['type']);
        static::assertSame('or', $wheres[0]['boolean']);
    }
}
