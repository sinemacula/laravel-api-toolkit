<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
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
 * @SuppressWarnings("php:S1448")
 *
 * @internal
 */
#[CoversClass(ContainsOperator::class)]
final class ContainsOperatorTest extends TestCase
{
    /** @var string */
    private const string TYPE_JSON_CONTAINS = 'JsonContains';

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
        static::assertCount(1, $wheres);
        static::assertSame(self::TYPE_JSON_CONTAINS, $wheres[0]['type']);
        static::assertSame('tags', $wheres[0]['column']);
        static::assertSame(['Alice'], $wheres[0]['value']);
        static::assertSame('and', $wheres[0]['boolean']);
    }

    /**
     * Test that apply with an object value uses whereJsonContains.
     *
     * @return void
     */
    public function testApplyWithObjectUsesWhereJsonContains(): void
    {
        $query = (new User)->newQuery();
        $value = (object) ['key' => 'value'];

        $this->operator->apply($query, 'tags', $value, FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame(self::TYPE_JSON_CONTAINS, $wheres[0]['type']);
        static::assertSame('tags', $wheres[0]['column']);
        static::assertSame($value, $wheres[0]['value']);
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
        static::assertCount(1, $wheres);
        static::assertSame('Nested', $wheres[0]['type']);

        $nested = $wheres[0]['query']->wheres;

        static::assertCount(2, $nested);
        static::assertSame(self::TYPE_JSON_CONTAINS, $nested[0]['type']);
        static::assertSame('Alice', $nested[0]['value']);
        static::assertSame('and', $nested[0]['boolean']);
        static::assertSame(self::TYPE_JSON_CONTAINS, $nested[1]['type']);
        static::assertSame('Bob', $nested[1]['value']);
        static::assertSame('or', $nested[1]['boolean']);
    }

    /**
     * Test that apply with a comma-separated string trims items and
     * drops empty segments.
     *
     * @return void
     */
    public function testApplyWithCommaSeparatedStringTrimsAndDropsEmptyItems(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'tags', ' Alice , , Bob ', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame('Nested', $wheres[0]['type']);

        $values = array_column($wheres[0]['query']->wheres, 'value');

        static::assertSame(['Alice', 'Bob'], $values);
    }

    /**
     * Test that apply with a string containing only commas adds no
     * constraints.
     *
     * @return void
     */
    public function testApplyWithOnlyCommasAddsNoConstraints(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'tags', ',,', FilterContext::root());

        static::assertSame([], $query->getQuery()->wheres);
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
        static::assertCount(1, $wheres);
        static::assertSame(self::TYPE_JSON_CONTAINS, $wheres[0]['type']);
        static::assertSame('tags', $wheres[0]['column']);
        static::assertSame('Alice', $wheres[0]['value']);
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
        static::assertCount(1, $wheres);
        static::assertSame(self::TYPE_JSON_CONTAINS, $wheres[0]['type']);
        static::assertSame('["a"]', $wheres[0]['value']);
    }

    /**
     * Test that a valid JSON string containing commas is passed to
     * whereJsonContains untouched rather than being split.
     *
     * @return void
     */
    public function testApplyWithJsonStringContainingCommaIsNotSplit(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'tags', '["a","b"]', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertCount(1, $wheres);
        static::assertSame(self::TYPE_JSON_CONTAINS, $wheres[0]['type']);
        static::assertSame('["a","b"]', $wheres[0]['value']);
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

    /**
     * Test that the comma-split conditions are grouped inside a single
     * nested where on the parent query.
     *
     * @return void
     */
    public function testApplyWithCommaSeparatedStringGroupsConditionsInSingleNestedWhere(): void
    {
        $query = (new User)->newQuery();

        $query->where('name', 'Alice');

        $this->operator->apply($query, 'tags', 'php,laravel', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertCount(2, $wheres);
        static::assertSame('Nested', $wheres[1]['type']);
        static::assertInstanceOf(Builder::class, $query);
    }
}
