<?php

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\BetweenOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\ContainsOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\EqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\GreaterThanOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\GreaterThanOrEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\InOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LessThanOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LessThanOrEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LikeOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotNullOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NullOperator;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the FilterApplier concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 * @SuppressWarnings("php:S1448")
 *
 * @internal
 */
#[CoversClass(FilterApplier::class)]
class FilterApplierTest extends TestCase
{
    /** @var string */
    private const string OPERATOR_CONTAINS = '$contains';

    /** @var \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider */
    private SchemaIntrospectionProvider $schemaIntrospector;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry */
    private OperatorRegistry $operatorRegistry;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier */
    private FilterApplier $applier;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaIntrospector = $this->createMock(SchemaIntrospectionProvider::class);

        $this->schemaIntrospector->method('isSearchable')->willReturnCallback(
            fn (Model $model, string $column) => in_array($column, ['name', 'email', 'id', 'organization_id', 'title', 'password'], true),
        );

        $this->schemaIntrospector->method('isRelation')->willReturnCallback(
            fn (string $key, Model $model) => in_array($key, ['posts', 'organization'], true),
        );

        $this->operatorRegistry = new OperatorRegistry;
        $this->operatorRegistry->register('$eq', new EqualOperator);
        $this->operatorRegistry->register('$neq', new NotEqualOperator);
        $this->operatorRegistry->register('$gt', new GreaterThanOperator);
        $this->operatorRegistry->register('$lt', new LessThanOperator);
        $this->operatorRegistry->register('$ge', new GreaterThanOrEqualOperator);
        $this->operatorRegistry->register('$le', new LessThanOrEqualOperator);
        $this->operatorRegistry->register('$like', new LikeOperator);
        $this->operatorRegistry->register('$in', new InOperator);
        $this->operatorRegistry->register('$between', new BetweenOperator);
        $this->operatorRegistry->register('$contains', new ContainsOperator);
        $this->operatorRegistry->register('$null', new NullOperator);
        $this->operatorRegistry->register('$notNull', new NotNullOperator);

        $this->applier = new FilterApplier;
    }

    /**
     * Test that apply with null filters returns an unmodified query.
     *
     * @return void
     */
    public function testApplyWithNullFiltersReturnsUnmodifiedQuery(): void
    {
        $result = $this->applyFilters(null);

        static::assertEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that apply with empty filters returns an unmodified query.
     *
     * @return void
     */
    public function testApplyWithEmptyFiltersReturnsUnmodifiedQuery(): void
    {
        $result = $this->applyFilters([]);

        static::assertEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that apply with a simple filter applies a where clause.
     *
     * @return void
     */
    public function testApplyWithSimpleFilterAppliesWhereClause(): void
    {
        $result = $this->applyFilters(['name' => 'Alice']);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('name', $wheres[0]['column']);
        static::assertSame('Alice', $wheres[0]['value']);
    }

    /**
     * Test that $eq operator applies an equals condition.
     *
     * @return void
     */
    public function testApplyWithEqOperatorAppliesEqualsCondition(): void
    {
        $result = $this->applyFilters(['name' => ['$eq' => 'Alice']]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('Basic', $wheres[0]['type']);
        static::assertSame('=', $wheres[0]['operator']);
        static::assertSame('Alice', $wheres[0]['value']);
    }

    /**
     * Test that $neq operator applies a not-equals condition.
     *
     * @return void
     */
    public function testApplyWithNeqOperatorAppliesNotEqualsCondition(): void
    {
        $result = $this->applyFilters(['name' => ['$neq' => 'Alice']]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('<>', $wheres[0]['operator']);
    }

    /**
     * Test that $gt operator applies a greater-than condition.
     *
     * @return void
     */
    public function testApplyWithGtOperatorAppliesGreaterThan(): void
    {
        $result = $this->applyFilters(['id' => ['$gt' => 10]]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('>', $wheres[0]['operator']);
    }

    /**
     * Test that $lt operator applies a less-than condition.
     *
     * @return void
     */
    public function testApplyWithLtOperatorAppliesLessThan(): void
    {
        $result = $this->applyFilters(['id' => ['$lt' => 10]]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('<', $wheres[0]['operator']);
    }

    /**
     * Test that $ge operator applies a greater-than-or-equal condition.
     *
     * @return void
     */
    public function testApplyWithGeOperatorAppliesGreaterThanOrEqual(): void
    {
        $result = $this->applyFilters(['id' => ['$ge' => 10]]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('>=', $wheres[0]['operator']);
    }

    /**
     * Test that $le operator applies a less-than-or-equal condition.
     *
     * @return void
     */
    public function testApplyWithLeOperatorAppliesLessThanOrEqual(): void
    {
        $result = $this->applyFilters(['id' => ['$le' => 10]]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('<=', $wheres[0]['operator']);
    }

    /**
     * Test that $like operator wraps value with percent signs.
     *
     * @return void
     */
    public function testApplyWithLikeOperatorWrapsValueWithPercent(): void
    {
        $result = $this->applyFilters(['name' => ['$like' => 'Ali']]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('%Ali%', $wheres[0]['value']);
    }

    /**
     * Test that $in operator uses whereIn.
     *
     * @return void
     */
    public function testApplyWithInOperatorUsesWhereIn(): void
    {
        $result = $this->applyFilters(['name' => ['$in' => ['Alice', 'Bob']]]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('In', $wheres[0]['type']);
        static::assertSame(['Alice', 'Bob'], $wheres[0]['values']);
    }

    /**
     * Test that $between operator uses whereBetween.
     *
     * @return void
     */
    public function testApplyWithBetweenOperatorUsesWhereBetween(): void
    {
        $result = $this->applyFilters(['id' => ['$between' => [1, 10]]]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('between', $wheres[0]['type']);
    }

    /**
     * Test that $between with wrong array size is ignored.
     *
     * @return void
     */
    public function testApplyWithBetweenWrongArraySizeIsIgnored(): void
    {
        $result = $this->applyFilters(['id' => ['$between' => [1]]]);

        static::assertEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that $null operator adds whereNull.
     *
     * @return void
     */
    public function testApplyWithNullOperatorAddsWhereNull(): void
    {
        $result = $this->applyFilters(['organization_id' => ['$null' => true]]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('Null', $wheres[0]['type']);
        static::assertSame('organization_id', $wheres[0]['column']);
    }

    /**
     * Test that $notNull operator adds whereNotNull.
     *
     * @return void
     */
    public function testApplyWithNotNullOperatorAddsWhereNotNull(): void
    {
        $result = $this->applyFilters(['organization_id' => ['$notNull' => true]]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('NotNull', $wheres[0]['type']);
    }

    /**
     * Test that $contains with an array uses whereJsonContains.
     *
     * @return void
     */
    public function testApplyWithContainsArrayUsesWhereJsonContains(): void
    {
        $result = $this->applyFilters(['name' => [self::OPERATOR_CONTAINS => ['Alice']]]);

        static::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that $contains with a comma-separated string creates multiple
     * JSON contains conditions.
     *
     * @return void
     */
    public function testApplyWithContainsCommaSeparatedStringCreatesMultipleConditions(): void
    {
        $result = $this->applyFilters(['name' => [self::OPERATOR_CONTAINS => 'Alice,Bob']]);

        static::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that $contains with a plain string uses whereJsonContains.
     *
     * @return void
     */
    public function testApplyWithContainsPlainStringUsesWhereJsonContains(): void
    {
        $result = $this->applyFilters(['name' => [self::OPERATOR_CONTAINS => 'Alice']]);

        static::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that $has operator adds whereHas.
     *
     * @return void
     */
    public function testApplyWithHasOperatorAddsWhereHas(): void
    {
        $result = $this->applyFilters(['$has' => ['posts']]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that $hasnt operator adds whereDoesntHave.
     *
     * @return void
     */
    public function testApplyWithHasntOperatorAddsWhereDoesntHave(): void
    {
        $result = $this->applyFilters(['$hasnt' => ['posts']]);
        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('NotExists', $wheres[0]['type']);
    }

    /**
     * Test that $has with a named relation and conditions applies
     * constrained whereHas.
     *
     * @return void
     */
    public function testApplyWithHasNamedRelationAndConditions(): void
    {
        $result = $this->applyFilters([
            '$has' => [
                'posts' => ['title' => ['$like' => 'test']],
            ],
        ]);

        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that $or logical operator groups conditions.
     *
     * @return void
     */
    public function testApplyWithOrLogicalOperatorGroupsConditions(): void
    {
        $result = $this->applyFilters([
            '$or' => [
                'name'  => 'Alice',
                'email' => 'bob@example.com',
            ],
        ]);

        static::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that $and logical operator groups conditions.
     *
     * @return void
     */
    public function testApplyWithAndLogicalOperatorGroupsConditions(): void
    {
        $result = $this->applyFilters([
            '$and' => [
                'name'  => 'Alice',
                'email' => 'alice@example.com',
            ],
        ]);

        static::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that nested logical operators produce nested grouping.
     *
     * @return void
     */
    public function testApplyWithNestedLogicalOperators(): void
    {
        $result = $this->applyFilters([
            '$and' => [
                '$or' => [
                    'name'  => 'Alice',
                    'email' => 'alice@example.com',
                ],
            ],
        ]);

        static::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that a relation filter applies whereHas with nested conditions.
     *
     * @return void
     */
    public function testApplyWithRelationFilterAppliesWhereHas(): void
    {
        $result = $this->applyFilters([
            'posts' => ['title' => ['$like' => 'test']],
        ]);

        $wheres = $result->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that $or inside a relation filter creates a grouped orWhere.
     *
     * @return void
     */
    public function testApplyWithOrInsideRelationFilterCreatesOrWhereGroup(): void
    {
        $result = $this->applyFilters([
            'posts' => [
                '$or' => [
                    'title' => ['$like' => 'test'],
                ],
            ],
        ]);

        static::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that $or combined with $has uses orWhereHas.
     *
     * @return void
     */
    public function testApplyWithOrAndHasUsesOrWhereHas(): void
    {
        $result = $this->applyFilters([
            '$or' => [
                '$has' => ['posts'],
            ],
        ]);

        static::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that a non-searchable column is ignored.
     *
     * @return void
     */
    public function testApplyWithNonSearchableColumnIsIgnored(): void
    {
        $result = $this->applyFilters(['nonexistent_column' => 'value']);

        static::assertEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that $notNull under $or uses orWhereNotNull.
     *
     * @return void
     */
    public function testApplyWithNotNullUnderOrUsesOrWhereNotNull(): void
    {
        $result = $this->applyFilters([
            '$or' => [
                'organization_id' => ['$notNull' => true],
            ],
        ]);

        static::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Apply filters using the FilterApplier and return the resulting
     * query builder.
     *
     * @param  array<string, mixed>|null  $filters
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function applyFilters(?array $filters): Builder
    {
        return $this->applier->apply(
            (new User)->newQuery(),
            $filters,
            $this->schemaIntrospector,
            $this->operatorRegistry,
        );
    }
}
