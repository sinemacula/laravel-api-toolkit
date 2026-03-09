<?php

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NullOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the NullOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NullOperator::class)]
class NullOperatorTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Operators\NullOperator */
    private NullOperator $operator;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = new NullOperator;
    }

    /**
     * Test that apply adds a whereNull constraint.
     *
     * @return void
     */
    public function testApplyAddsWhereNull(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'name', true, FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertSame('Null', $wheres[0]['type']);
        static::assertSame('name', $wheres[0]['column']);
    }

    /**
     * Test that apply in $or context uses orWhereNull.
     *
     * @return void
     */
    public function testNullApplyInOrContextUsesOrWhereNull(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'name', true, FilterContext::nested('$or', FilterContext::root()));

        $wheres = $query->getQuery()->wheres;

        static::assertSame('Null', $wheres[0]['type']);
        static::assertSame('or', $wheres[0]['boolean']);
    }
}
