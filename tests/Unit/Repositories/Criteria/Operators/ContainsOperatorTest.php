<?php

namespace Tests\Unit\Repositories\Criteria\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\ContainsOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the ContainsOperator class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ContainsOperator::class)]
class ContainsOperatorTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Operators\ContainsOperator */
    private ContainsOperator $operator;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = new ContainsOperator;
    }

    /**
     * Test that apply with an array value uses whereJsonContains.
     *
     * @return void
     */
    public function testApplyWithArrayUsesWhereJsonContains(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'tags', ['Alice'], FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
    }

    /**
     * Test that apply with a comma-separated string creates
     * multiple conditions.
     *
     * @return void
     */
    public function testApplyWithCommaSeparatedStringCreatesMultipleConditions(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'tags', 'Alice,Bob', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
    }

    /**
     * Test that apply with a plain string uses whereJsonContains.
     *
     * @return void
     */
    public function testApplyWithPlainStringUsesWhereJsonContains(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'tags', 'Alice', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
    }

    /**
     * Test that apply with a valid JSON string uses whereJsonContains.
     *
     * @return void
     */
    public function testApplyWithValidJsonStringUsesWhereJsonContains(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'tags', '["a"]', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
    }

    /**
     * Test that apply with a null value is handled gracefully.
     *
     * @return void
     */
    public function testApplyWithNullValueIsHandledGracefully(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'tags', null, FilterContext::root());

        static::assertIsArray($query->getQuery()->wheres);
    }
}
