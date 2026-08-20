<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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

        self::assertNotEmpty($wheres);
        self::assertCount(1, $wheres);
        self::assertSame(self::TYPE_JSON_CONTAINS, $wheres[0]['type']);
        self::assertSame('tags', $wheres[0]['column']);
        self::assertSame(['Alice'], $wheres[0]['value']);
        self::assertSame('and', $wheres[0]['boolean']);
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

        self::assertCount(1, $wheres);
        self::assertSame(self::TYPE_JSON_CONTAINS, $wheres[0]['type']);
        self::assertSame('tags', $wheres[0]['column']);
        self::assertSame($value, $wheres[0]['value']);
    }

    /**
     * Test that apply with a comma-separated string creates multiple
     * conditions.
     *
     * @return void
     */
    public function testApplyWithCommaSeparatedStringCreatesMultipleConditions(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'tags', 'Alice,Bob', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertCount(1, $wheres);
        self::assertSame('Nested', $wheres[0]['type']);

        $nested = $wheres[0]['query']->wheres;

        self::assertCount(2, $nested);
        self::assertSame(self::TYPE_JSON_CONTAINS, $nested[0]['type']);
        self::assertSame('Alice', $nested[0]['value']);
        self::assertSame('and', $nested[0]['boolean']);
        self::assertSame(self::TYPE_JSON_CONTAINS, $nested[1]['type']);
        self::assertSame('Bob', $nested[1]['value']);
        self::assertSame('or', $nested[1]['boolean']);
    }

    /**
     * Test that apply with a comma-separated string trims items and drops empty
     * segments.
     *
     * @return void
     */
    public function testApplyWithCommaSeparatedStringTrimsAndDropsEmptyItems(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'tags', ' Alice , , Bob ', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame('Nested', $wheres[0]['type']);

        $values = array_column($wheres[0]['query']->wheres, 'value');

        self::assertSame(['Alice', 'Bob'], $values);
    }

    /**
     * Test that apply with a string containing only commas adds no constraints.
     *
     * @return void
     */
    public function testApplyWithOnlyCommasAddsNoConstraints(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'tags', ',,', FilterContext::root());

        self::assertSame([], $query->getQuery()->wheres);
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

        self::assertNotEmpty($wheres);
        self::assertCount(1, $wheres);
        self::assertSame(self::TYPE_JSON_CONTAINS, $wheres[0]['type']);
        self::assertSame('tags', $wheres[0]['column']);
        self::assertSame('Alice', $wheres[0]['value']);
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

        self::assertNotEmpty($wheres);
        self::assertCount(1, $wheres);
        self::assertSame(self::TYPE_JSON_CONTAINS, $wheres[0]['type']);
        self::assertSame('["a"]', $wheres[0]['value']);
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

        self::assertCount(1, $wheres);
        self::assertSame(self::TYPE_JSON_CONTAINS, $wheres[0]['type']);
        self::assertSame('["a","b"]', $wheres[0]['value']);
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

        $wheres = $query->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame(self::TYPE_JSON_CONTAINS, $wheres[0]['type']);
    }

    /**
     * Test that a grammar rejection of the JSON-contains clause propagates
     * rather than dropping the predicate and widening the result set.
     *
     * @return void
     */
    public function testApplyPropagatesWhenTheGrammarRejectsTheJsonContainsConstraint(): void
    {
        $base = \Mockery::mock(QueryBuilder::class);
        $base->shouldReceive('whereJsonContains')
            ->once()
            ->andThrow(new \RuntimeException('grammar rejects json-contains'));

        $query = \Mockery::mock(Builder::class);
        $query->shouldReceive('getQuery')->andReturn($base);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('grammar rejects json-contains');

        $this->operator->apply($query, 'tags', null, FilterContext::root());
    }

    /**
     * Test that a non-string, non-empty scalar is routed to the defensive
     * containment path and recorded verbatim rather than fed to JSON
     * validation, which would reject a non-string argument.
     *
     * @return void
     */
    public function testApplyWithIntegerValueUsesWhereJsonContains(): void
    {
        $query = (new User)->newQuery();

        $this->operator->apply($query, 'tags', 123, FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame(self::TYPE_JSON_CONTAINS, $wheres[0]['type']);
        self::assertSame('tags', $wheres[0]['column']);
        self::assertSame(123, $wheres[0]['value']);
    }

    /**
     * Test that a grammar rejection of an array payload propagates too, so
     * every containment path fails the request rather than dropping it.
     *
     * @return void
     */
    public function testApplyWithArrayThatTheGrammarRejectsPropagatesTheException(): void
    {
        $base = \Mockery::mock(QueryBuilder::class);
        $base->shouldReceive('whereJsonContains')
            ->andThrow(new \RuntimeException('grammar rejects json-contains'));

        $query = \Mockery::mock(Builder::class);
        $query->shouldReceive('getQuery')->andReturn($base);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('grammar rejects json-contains');

        $this->operator->apply($query, 'tags', ['Alice'], FilterContext::root());
    }

    /**
     * Test that the comma-split conditions are grouped inside a single nested
     * where on the parent query.
     *
     * @return void
     */
    public function testApplyWithCommaSeparatedStringGroupsConditionsInSingleNestedWhere(): void
    {
        $query = (new User)->newQuery();

        $query->where('name', 'Alice');

        $this->operator->apply($query, 'tags', 'php,laravel', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        self::assertCount(2, $wheres);
        self::assertSame('Nested', $wheres[1]['type']);
        self::assertInstanceOf(Builder::class, $query);
    }

    /**
     * Test that apply honours the OR boolean of the current filter context so a
     * direct $contains branch inside an $or group is combined with OR rather
     * than AND.
     *
     * @return void
     */
    public function testApplyInOrContextUsesOrBoolean(): void
    {
        $query = (new User)->newQuery();

        $query->where('name', 'Alice');

        $this->operator->apply($query, 'tags', ['php'], FilterContext::nested('$or'));

        $wheres = $query->getQuery()->wheres;

        self::assertCount(2, $wheres);
        self::assertSame(self::TYPE_JSON_CONTAINS, $wheres[1]['type']);
        self::assertSame('or', $wheres[1]['boolean']);
    }

    /**
     * Test that apply honours the OR boolean when grouping a comma-separated
     * $contains branch inside an $or group, so the nested group is combined with
     * OR rather than AND.
     *
     * @return void
     */
    public function testApplyCommaSeparatedInOrContextUsesOrBoolean(): void
    {
        $query = (new User)->newQuery();

        $query->where('name', 'Alice');

        $this->operator->apply($query, 'tags', 'php,laravel', FilterContext::nested('$or'));

        $wheres = $query->getQuery()->wheres;

        self::assertCount(2, $wheres);
        self::assertSame('Nested', $wheres[1]['type']);
        self::assertSame('or', $wheres[1]['boolean']);
    }
}
