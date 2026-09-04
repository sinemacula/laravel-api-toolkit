<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
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
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotNullOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NullOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\DeepTraversalPostResource;
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
final class FilterApplierTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /** @var string */
    private const string OPERATOR_CONTAINS = '$contains';

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

        $this->operatorRegistry = new OperatorRegistry;
        $this->operatorRegistry->register('$eq', new EqualOperator);
        $this->operatorRegistry->register('$neq', new NotEqualOperator);
        $this->operatorRegistry->register('$gt', new GreaterThanOperator);
        $this->operatorRegistry->register('$lt', new LessThanOperator);
        $this->operatorRegistry->register('$ge', new GreaterThanOrEqualOperator);
        $this->operatorRegistry->register('$le', new LessThanOrEqualOperator);
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
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNullFiltersReturnsUnmodifiedQuery(): void
    {
        $result = $this->applyFilters(null);

        self::assertEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that apply with empty filters returns an unmodified query.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithEmptyFiltersReturnsUnmodifiedQuery(): void
    {
        $result = $this->applyFilters([]);

        self::assertEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that apply with a simple filter applies a where clause.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithSimpleFilterAppliesWhereClause(): void
    {
        $result = $this->applyFilters(['name' => 'Alice']);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('name', $wheres[0]['column']);
        self::assertSame('Alice', $wheres[0]['value']);
        self::assertSame('and', $wheres[0]['boolean']);
    }

    /**
     * Test that $eq operator applies an equals condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithEqOperatorAppliesEqualsCondition(): void
    {
        $result = $this->applyFilters(['name' => ['$eq' => 'Alice']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Basic', $wheres[0]['type']);
        self::assertSame('=', $wheres[0]['operator']);
        self::assertSame('Alice', $wheres[0]['value']);
    }

    /**
     * Test that $neq operator applies a not-equals condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNeqOperatorAppliesNotEqualsCondition(): void
    {
        $result = $this->applyFilters(['status' => ['$neq' => 'active']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('<>', $wheres[0]['operator']);
    }

    /**
     * Test that $gt operator applies a greater-than condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithGtOperatorAppliesGreaterThan(): void
    {
        $result = $this->applyFilters(['id' => ['$gt' => 10]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('>', $wheres[0]['operator']);
    }

    /**
     * Test that $lt operator applies a less-than condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithLtOperatorAppliesLessThan(): void
    {
        $result = $this->applyFilters(['id' => ['$lt' => 10]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('<', $wheres[0]['operator']);
    }

    /**
     * Test that $ge operator applies a greater-than-or-equal condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithGeOperatorAppliesGreaterThanOrEqual(): void
    {
        $result = $this->applyFilters(['id' => ['$ge' => 10]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('>=', $wheres[0]['operator']);
    }

    /**
     * Test that $le operator applies a less-than-or-equal condition.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithLeOperatorAppliesLessThanOrEqual(): void
    {
        $result = $this->applyFilters(['id' => ['$le' => 10]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('<=', $wheres[0]['operator']);
    }

    /**
     * Test that $in operator uses whereIn.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithInOperatorUsesWhereIn(): void
    {
        $result = $this->applyFilters(['name' => ['$in' => ['Alice', 'Bob']]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('In', $wheres[0]['type']);
        self::assertSame(['Alice', 'Bob'], $wheres[0]['values']);
    }

    /**
     * Test that $between operator uses whereBetween.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithBetweenOperatorUsesWhereBetween(): void
    {
        $result = $this->applyFilters(['id' => ['$between' => [1, 10]]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('between', $wheres[0]['type']);
    }

    /**
     * Test that $between with wrong array size is ignored.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithBetweenWrongArraySizeIsIgnored(): void
    {
        $result = $this->applyFilters(['id' => ['$between' => [1]]]);

        self::assertEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that $null operator adds whereNull.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNullOperatorAddsWhereNull(): void
    {
        $result = $this->applyFilters(['organization_id' => ['$null' => true]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Null', $wheres[0]['type']);
        self::assertSame('organization_id', $wheres[0]['column']);
    }

    /**
     * Test that $notNull operator adds whereNotNull.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNotNullOperatorAddsWhereNotNull(): void
    {
        $result = $this->applyFilters(['organization_id' => ['$notNull' => true]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('NotNull', $wheres[0]['type']);
    }

    /**
     * Test that $contains with an array uses whereJsonContains.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithContainsArrayUsesWhereJsonContains(): void
    {
        $result = $this->applyFilters(['context' => [self::OPERATOR_CONTAINS => ['php']]]);

        self::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that $contains with a comma-separated string creates multiple JSON
     * contains conditions.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithContainsCommaSeparatedStringCreatesMultipleConditions(): void
    {
        $result = $this->applyFilters(['context' => [self::OPERATOR_CONTAINS => 'php,rust']]);

        self::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that $contains with a plain string uses whereJsonContains.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithContainsPlainStringUsesWhereJsonContains(): void
    {
        $result = $this->applyFilters(['context' => [self::OPERATOR_CONTAINS => 'php']]);

        self::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that $has operator adds whereHas.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithHasOperatorAddsWhereHas(): void
    {
        $result = $this->applyFilters(['$has' => ['posts']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);
        self::assertSame('and', $wheres[0]['boolean']);
    }

    /**
     * Test that $hasnt operator adds whereDoesntHave.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithHasntOperatorAddsWhereDoesntHave(): void
    {
        $result = $this->applyFilters(['$hasnt' => ['posts']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('NotExists', $wheres[0]['type']);
    }

    /**
     * Test that $has with a named relation and conditions applies constrained
     * whereHas.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithHasNamedRelationAndConditions(): void
    {
        $result = $this->applyFilters([
            '$has' => [
                'posts' => ['title' => ['$eq' => 'test']],
            ],
        ]);

        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);

        /** @var \Illuminate\Database\Query\Builder $subQuery */
        $subQuery = $wheres[0]['query'];

        $subWheres = $this->relationGroupWheres($subQuery);

        self::assertTrue(
            $subWheres->contains(fn (array $where): bool => ($where['column'] ?? null) === 'title' && ($where['value'] ?? null) === 'test'),
        );
    }

    /**
     * Test that $or logical operator groups conditions.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithOrLogicalOperatorGroupsConditions(): void
    {
        $result = $this->applyFilters([
            '$or' => [
                'name'  => 'Alice',
                'email' => 'bob@example.com',
            ],
        ]);

        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertCount(1, $wheres);
        self::assertSame('Nested', $wheres[0]['type']);
        self::assertSame('or', $wheres[0]['boolean']);

        $nested = $wheres[0]['query']->wheres;

        self::assertCount(2, $nested);
        self::assertSame('name', $nested[0]['column']);
        self::assertSame('or', $nested[0]['boolean']);
        self::assertSame('email', $nested[1]['column']);
        self::assertSame('or', $nested[1]['boolean']);
    }

    /**
     * Test that $and logical operator groups conditions.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithAndLogicalOperatorGroupsConditions(): void
    {
        $result = $this->applyFilters([
            '$and' => [
                'name'  => 'Alice',
                'email' => 'alice@example.com',
            ],
        ]);

        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertCount(1, $wheres);
        self::assertSame('Nested', $wheres[0]['type']);
        self::assertSame('and', $wheres[0]['boolean']);

        $nested = $wheres[0]['query']->wheres;

        self::assertCount(2, $nested);
        self::assertSame('and', $nested[0]['boolean']);
        self::assertSame('and', $nested[1]['boolean']);
    }

    /**
     * Test that nested logical operators produce nested grouping.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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

        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertCount(1, $wheres);
        self::assertSame('Nested', $wheres[0]['type']);
        self::assertSame('and', $wheres[0]['boolean']);

        $andGroup = $wheres[0]['query']->wheres;

        self::assertCount(1, $andGroup);
        self::assertSame('Nested', $andGroup[0]['type']);
        self::assertSame('and', $andGroup[0]['boolean']);

        $orGroup = $andGroup[0]['query']->wheres;

        self::assertCount(2, $orGroup);
        self::assertSame('or', $orGroup[0]['boolean']);
        self::assertSame('or', $orGroup[1]['boolean']);
    }

    /**
     * Test that a relation filter applies whereHas with nested conditions.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithRelationFilterAppliesWhereHas(): void
    {
        $result = $this->applyFilters([
            'posts' => ['title' => ['$eq' => 'test']],
        ]);

        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);
        self::assertSame('and', $wheres[0]['boolean']);

        /** @var \Illuminate\Database\Query\Builder $subQuery */
        $subQuery = $wheres[0]['query'];

        $subWheres = $this->relationGroupWheres($subQuery);

        self::assertTrue(
            $subWheres->contains(fn (array $where): bool => ($where['column'] ?? null) === 'title' && ($where['value'] ?? null) === 'test'),
        );
    }

    /**
     * Test that a relation filter under $or uses orWhereHas.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithRelationFilterUnderOrUsesOrWhereHas(): void
    {
        $result = $this->applyFilters([
            '$or' => [
                'nested' => [
                    'posts' => ['title' => ['$eq' => 'test']],
                ],
            ],
        ]);

        $wheres = $result->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame('Nested', $wheres[0]['type']);

        $nested = $wheres[0]['query']->wheres;

        self::assertCount(1, $nested);
        self::assertSame('Exists', $nested[0]['type']);
        self::assertSame('or', $nested[0]['boolean']);
    }

    /**
     * Test that the conditions inside a relation filter under $or are combined
     * with AND, so the OR stays at the parent level rather than being combined
     * with the relation's own correlation predicate.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testConditionsInsideARelationFilterUnderOrAreCombinedWithAnd(): void
    {
        $result = $this->applyFilters([
            '$or' => [
                'nested' => [
                    'posts' => ['title' => 'first', 'id' => '1'],
                ],
            ],
        ]);

        $nested = $result->getQuery()->wheres[0]['query']->wheres;

        self::assertSame('Exists', $nested[0]['type']);
        self::assertSame('or', $nested[0]['boolean']);

        $booleans = $this->relationGroupWheres($nested[0]['query'])->pluck('boolean')->all();

        self::assertSame(['and', 'and'], $booleans);
    }

    /**
     * Test that $or inside a relation filter creates a grouped orWhere.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithOrInsideRelationFilterCreatesOrWhereGroup(): void
    {
        $result = $this->applyFilters([
            'posts' => [
                '$or' => [
                    'title' => ['$eq' => 'test'],
                    'id'    => ['$eq' => 1],
                ],
            ],
        ]);

        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);

        /** @var \Illuminate\Database\Query\Builder $subQuery */
        $subQuery = $wheres[0]['query'];

        $subWheres = $this->relationGroupWheres($subQuery);

        /** @var array{type: string, boolean: string, query: \Illuminate\Database\Query\Builder}|null $group */
        $group = $subWheres->first(fn (array $where): bool => $where['type'] === 'Nested');

        self::assertNotNull($group);
        self::assertSame('and', $group['boolean']);

        $orWheres = $group['query']->wheres;

        self::assertCount(2, $orWheres);
        self::assertSame('title', $orWheres[0]['column']);
        self::assertSame('test', $orWheres[0]['value']);
        self::assertSame('or', $orWheres[0]['boolean']);
        self::assertSame('or', $orWheres[1]['boolean']);
    }

    /**
     * Test that $or combined with $has uses orWhereHas.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithOrAndHasUsesOrWhereHas(): void
    {
        $result = $this->applyFilters([
            '$or' => [
                '$has' => ['posts'],
            ],
        ]);

        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Nested', $wheres[0]['type']);

        $nested = $wheres[0]['query']->wheres;

        self::assertCount(1, $nested);
        self::assertSame('Exists', $nested[0]['type']);
        self::assertSame('or', $nested[0]['boolean']);
    }

    /**
     * Test that $hasnt under $or adds whereDoesntHave inside the group.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithOrAndHasntAddsWhereDoesntHave(): void
    {
        $result = $this->applyFilters([
            '$or' => [
                '$hasnt' => ['posts'],
            ],
        ]);

        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Nested', $wheres[0]['type']);

        $nested = $wheres[0]['query']->wheres;

        self::assertCount(1, $nested);
        self::assertSame('NotExists', $nested[0]['type']);
    }

    /**
     * Test that a $has filter is not double-applied as a column condition when
     * a handler happens to be registered for the $has token in the operator
     * registry.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testHasFilterIsNotReappliedAsColumnConditionWhenRegistered(): void
    {
        $this->operatorRegistry->register('$has', new EqualOperator);

        $result = $this->applyFilters(['name' => ['$has' => 'posts']]);
        $wheres = $result->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that an undeclared column is rejected.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithUndeclaredColumnIsRejected(): void
    {
        $this->assertRejectsKey(['nonexistent_column' => 'value'], 'nonexistent_column');
    }

    /**
     * Test that $notNull under $or uses orWhereNotNull.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNotNullUnderOrUsesOrWhereNotNull(): void
    {
        $result = $this->applyFilters([
            '$or' => [
                'organization_id' => ['$notNull' => true],
            ],
        ]);

        self::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that an undeclared key is rejected at the key itself rather than
     * after descending into its value, so the error names what the client sent
     * at that level.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredKeyIsRejectedBeforeDescendingIntoItsValue(): void
    {
        $this->assertRejectsKey(['ghost' => ['deeper' => 'value']], 'ghost');
    }

    /**
     * Test that an undeclared column inside a logical group is rejected, so the
     * group is not a way around the declared surface.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredColumnInsideALogicalGroupIsRejected(): void
    {
        $this->assertRejectsKey(['$or' => ['nonexistent_column' => 'value']], 'nonexistent_column');
    }

    /**
     * Test that an undeclared column carrying a condition operator inside a
     * logical group is rejected before the operator handler runs.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredColumnUnderAnOperatorInsideALogicalGroupIsRejected(): void
    {
        $this->assertRejectsKey(['$or' => ['nonexistent_column' => ['$eq' => 'Alice']]], 'nonexistent_column');
    }

    /**
     * Test that a relation listed by an existence operator is rejected when the
     * resource does not declare it traversable.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredRelationListedByAnExistenceOperatorIsRejected(): void
    {
        $this->assertRejectsKey(['$has' => ['organization']], 'organization');
    }

    /**
     * Test that a named relation carrying conditions under an existence
     * operator is rejected when the resource does not declare it traversable.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredNamedRelationUnderAnExistenceOperatorIsRejected(): void
    {
        $this->assertRejectsKey(['$has' => ['organization' => ['name' => 'Acme']]], 'organization');
    }

    /**
     * Test that a condition operator applied to a column that fails the query
     * surface guard is rejected rather than reaching the operator handler.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testConditionOperatorOnGuardedColumnIsRejected(): void
    {
        $this->assertRejectsKey(['forbidden_column' => ['$eq' => 'Alice']], 'forbidden_column');
    }

    /**
     * Test that an operator the declaring column's capability does not answer
     * is rejected before its handler runs, leaving the query untouched.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRefusedOperatorIsRejectedBeforeItsHandlerRuns(): void
    {
        $this->assertRejectsOperator(['name' => ['$neq' => 'Alice']], 'name', '$neq', '$eq, $in, $null, $notNull');
    }

    /**
     * Test that the bare shorthand is gated as the equality it compiles to, so
     * a column declaring only containment cannot be compared by equality
     * through the spelling that names no operator.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testBareShorthandIsGatedAsTheEqualityItCompilesTo(): void
    {
        $this->assertRejectsOperator(['context' => 'php'], 'context', '$eq', '$contains');
    }

    /**
     * Test that a refused operator inside a logical group is rejected, so the
     * group is not a way around the capability the column was declared with.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRefusedOperatorInsideALogicalGroupIsRejected(): void
    {
        $this->assertRejectsOperator(['$or' => ['id' => ['$contains' => 'php']]], 'id', '$contains', '$eq, $in, $gt, $ge, $lt, $le, $between, $null, $notNull');
    }

    /**
     * Test that a refused operator inside a traversed relation is rejected
     * against the related resource's declaration rather than the root's.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRefusedOperatorInsideARelationIsRejected(): void
    {
        $this->assertRejectsOperator(['posts' => ['title' => ['$gt' => 'a']]], 'title', '$gt', '$eq, $in, $null, $notNull');
    }

    /**
     * Test that a token the capability matrix does not govern reaches its
     * handler on a declared column, so an operator the application registered
     * stays usable rather than being refused everywhere.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testTokenTheMatrixDoesNotGovernReachesItsHandler(): void
    {
        $this->operatorRegistry->register('$starts', static function (Builder $query, string $column, mixed $value): void {
            $query->where($column, 'like', (is_scalar($value) ? (string) $value : '') . '%');
        });

        $result = $this->applyFilters(['name' => ['$starts' => 'Ali']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('like', $wheres[0]['operator']);
        self::assertSame('Ali%', $wheres[0]['value']);
    }

    /**
     * Test that a condition operator whose token is reported as registered but
     * resolves to no handler is skipped, leaving the query untouched.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testConditionOperatorWithNullHandlerIsSkipped(): void
    {
        // Force a token that the registry reports present but resolves to null,
        // so has() and resolve() disagree - the guard must drop it silently.
        $operators             = $this->getProperty($this->operatorRegistry, 'operators');
        $operators['$phantom'] = null;
        $this->setProperty($this->operatorRegistry, 'operators', $operators);

        $result = $this->applyFilters(['name' => ['$phantom' => 'Alice']]);

        self::assertEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that the published STRUCTURAL_OPERATORS grammar stays aligned with
     * the tokens the applier actually dispatches, so the OpenAPI exporter
     * (which reads the constant) cannot drift from real behaviour.
     *
     * @return void
     */
    public function testStructuralOperatorsMatchTheDispatchMaps(): void
    {
        $dispatched = array_merge(
            array_keys($this->getProperty($this->applier, 'logicalOperatorMap')),
            array_keys($this->getProperty($this->applier, 'relationalMethodMap')),
        );

        sort($dispatched);
        $declared = FilterApplier::STRUCTURAL_OPERATORS;
        sort($declared);

        self::assertSame($declared, $dispatched);
    }

    /**
     * Test that an undeclared filter key is rejected with a validation error
     * naming the key.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredKeyIsRejected(): void
    {
        $surface = $this->declaredSurface(filterable: ['name' => Capability::EXACT]);

        try {
            $this->applier->apply((new User)->newQuery(), ['unknown_key' => 'x'], $this->operatorRegistry, $surface);
            self::fail('Expected a ValidationException for an undeclared filter key.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "unknown_key" key is not a permitted query parameter for this resource.'],
                $exception->errors()['filters.unknown_key'] ?? [],
            );
        }
    }

    /**
     * Test that a declared filterable column applies a simple where clause.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeclaredFilterableColumnIsApplied(): void
    {
        $surface = $this->declaredSurface(filterable: ['name' => Capability::EXACT]);

        $result = $this->applier->apply((new User)->newQuery(), ['name' => 'Alice'], $this->operatorRegistry, $surface);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('name', $wheres[0]['column']);
        self::assertSame('Alice', $wheres[0]['value']);
    }

    /**
     * Test that a declared traversable relation routes to a relation filter.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeclaredTraversableRelationIsApplied(): void
    {
        $surface = $this->declaredSurface(
            relations  : ['posts'],
            resourceMap: [Post::class => DeepTraversalPostResource::class],
        );

        $result = $this->applier->apply((new User)->newQuery(), ['posts' => ['title' => 'test']], $this->operatorRegistry, $surface);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that condition and logical operators keep working when applied
     * against a declared column.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeclaredColumnPreservesConditionAndLogicalOperators(): void
    {
        $surface = $this->declaredSurface(filterable: ['name' => Capability::EXACT, 'email' => Capability::EXACT]);

        $eq = $this->applier->apply((new User)->newQuery(), ['name' => ['$eq' => 'Alice']], $this->operatorRegistry, $surface);

        self::assertSame('=', $eq->getQuery()->wheres[0]['operator']);

        $orFilters = ['$or' => ['name' => 'Alice', 'email' => 'bob@example.com']];

        $or = $this->applier->apply((new User)->newQuery(), $orFilters, $this->operatorRegistry, $surface);

        self::assertSame('Nested', $or->getQuery()->wheres[0]['type']);
        self::assertSame('or', $or->getQuery()->wheres[0]['boolean']);
    }

    /**
     * Test that the $has structural operator still routes to a relation
     * existence clause for a declared relation.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDeclaredRelationPreservesHasOperator(): void
    {
        $surface = $this->declaredSurface(relations: ['posts']);

        $result = $this->applier->apply((new User)->newQuery(), ['$has' => ['posts']], $this->operatorRegistry, $surface);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that a document nested to exactly the depth cap is applied.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDocumentNestedToExactlyTheDepthCapIsApplied(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', 3);

        $result = $this->applyFilters([
            '$or' => ['$and' => ['$or' => ['name' => 'Alice']]],
        ]);

        self::assertNotEmpty($result->getQuery()->wheres);
    }

    /**
     * Test that a document nested one level beyond the depth cap is rejected,
     * pointing at the level it was refused at.
     *
     * @return void
     */
    public function testDocumentNestedBeyondTheDepthCapIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', 3);

        $this->assertRejectedForCost(
            ['$or' => ['$and' => ['$or' => ['$and' => ['name' => 'Alice']]]]],
            QueryCostLimits::MAX_DEPTH,
            '/$or/$and/$or/$and',
            3,
            4,
        );
    }

    /**
     * Test that a relation subquery counts as a level, since each traversal
     * adds its own correlated subquery.
     *
     * @return void
     */
    public function testRelationTraversalCountsTowardTheDepthCap(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', 1);

        $this->assertRejectedForCost(
            ['posts' => ['nested' => ['user' => ['name' => 'Alice']]]],
            QueryCostLimits::MAX_DEPTH,
            '/posts/user',
            1,
            2,
        );
    }

    /**
     * Test that a document visiting exactly the node cap is applied.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDocumentVisitingExactlyTheNodeCapIsApplied(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 3);

        $result = $this->applyFilters([
            'name'  => 'Alice',
            'email' => 'alice@example.com',
            'id'    => '1',
        ]);

        self::assertCount(3, $result->getQuery()->wheres);
    }

    /**
     * Test that a document visiting one node too many is rejected.
     *
     * @return void
     */
    public function testDocumentVisitingOneNodeTooManyIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 3);

        $this->assertRejectedForCost(
            [
                'name'            => 'Alice',
                'email'           => 'alice@example.com',
                'id'              => '1',
                'organization_id' => '2',
            ],
            QueryCostLimits::MAX_NODES,
            '/organization_id',
            3,
            4,
        );
    }

    /**
     * Test that the keys inside a logical group count toward the node cap.
     *
     * @return void
     */
    public function testKeysInsideALogicalGroupCountTowardTheNodeCap(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 2);

        $this->assertRejectedForCost(
            ['$or' => ['name' => 'Alice', 'email' => 'alice@example.com']],
            QueryCostLimits::MAX_NODES,
            '/$or/email',
            2,
            3,
        );
    }

    /**
     * Test that the keys inside a traversed relation count toward the node cap.
     *
     * @return void
     */
    public function testKeysInsideARelationCountTowardTheNodeCap(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 2);

        $this->assertRejectedForCost(
            ['posts' => ['title' => 'first', 'id' => '1']],
            QueryCostLimits::MAX_NODES,
            '/posts/id',
            2,
            3,
        );
    }

    /**
     * Test that the keys inside a logical group within a traversed relation
     * count toward the node cap.
     *
     * @return void
     */
    public function testKeysInsideAGroupWithinARelationCountTowardTheNodeCap(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 2);

        $this->assertRejectedForCost(
            ['posts' => ['$or' => ['title' => 'first', 'id' => '1']]],
            QueryCostLimits::MAX_NODES,
            '/posts/$or/id',
            2,
            3,
        );
    }

    /**
     * Test that each relation listed by an existence operator counts toward the
     * node cap, reported at its position beneath the operator rather than at
     * the root.
     *
     * @return void
     */
    public function testRelationsListedByAnExistenceOperatorCountTowardTheNodeCap(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 2);

        $this->assertRejectedForCost(
            ['$has' => ['posts', 'organization']],
            QueryCostLimits::MAX_NODES,
            '/$has/1',
            2,
            3,
        );
    }

    /**
     * Test that a rejection inside a relation named by an existence operator
     * points at its position beneath the operator.
     *
     * @return void
     */
    public function testRejectionInsideANamedExistenceRelationPointsAtItsPosition(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 1);

        $this->assertRejectedForCost(
            ['$has' => ['posts' => ['title' => ['$in' => ['first', 'second']]]]],
            QueryCostLimits::MAX_IN_ITEMS,
            '/$has/posts/title/$in',
            1,
            2,
        );
    }

    /**
     * Test that an operator value list of exactly the item cap is applied.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testOperatorValueListAtExactlyTheItemCapIsApplied(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 3);

        $result = $this->applyFilters(['name' => ['$in' => ['Alice', 'Bob', 'Carol']]]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame(['Alice', 'Bob', 'Carol'], $wheres[0]['values']);
    }

    /**
     * Test that an operator value list one item over the cap is rejected before
     * the values are bound.
     *
     * @return void
     */
    public function testOperatorValueListOverTheItemCapIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 3);

        $this->assertRejectedForCost(
            ['name' => ['$in' => ['Alice', 'Bob', 'Carol', 'Dave']]],
            QueryCostLimits::MAX_IN_ITEMS,
            '/name/$in',
            3,
            4,
        );
    }

    /**
     * Test that a delimited value list of exactly the item cap is applied, so
     * the cap counts the items an operator reads rather than the shape of the
     * value it was handed.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDelimitedValueListAtExactlyTheItemCapIsApplied(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 3);

        $result = $this->applyFilters(['context' => [self::OPERATOR_CONTAINS => 'php,rust,go']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertCount(3, $wheres[0]['query']->wheres);
    }

    /**
     * Test that a delimited value list one item over the cap is rejected, so
     * the delimited spelling cannot outrun the list spelling.
     *
     * @return void
     */
    public function testDelimitedValueListOverTheItemCapIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 3);

        $this->assertRejectedForCost(
            ['context' => [self::OPERATOR_CONTAINS => 'php,rust,go,zig']],
            QueryCostLimits::MAX_IN_ITEMS,
            '/context/$contains',
            3,
            4,
        );
    }

    /**
     * Test that an operator value carrying no list counts as a single item, so
     * an ordinary scalar comparison is never measured as a list.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testScalarOperatorValueCountsAsOneItem(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 1);

        $result = $this->applyFilters(['name' => ['$eq' => 'Alice, Bob, Carol, Dave']]);
        $wheres = $result->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('Alice, Bob, Carol, Dave', $wheres[0]['value']);
    }

    /**
     * Test that a rejection inside a traversed relation points at the position
     * within the document rather than at the root.
     *
     * @return void
     */
    public function testRejectionInsideARelationPointsAtItsPosition(): void
    {
        Config::set('api-toolkit.query_cost.max_in_items', 1);

        $this->assertRejectedForCost(
            ['posts' => ['title' => ['$in' => ['first', 'second']]]],
            QueryCostLimits::MAX_IN_ITEMS,
            '/posts/title/$in',
            1,
            2,
        );
    }

    /**
     * Test that an $or nested below the top level of a relation filter is
     * grouped rather than emitted as the scope's first clause.
     *
     * The relation scope booleans its whole slice with the first clause added
     * to it. An `or` there disjoins the correlation predicate the scope wrote
     * before the callback ran, so the existence check passes for every parent
     * row as soon as any child in the table matches, widening the result set
     * instead of narrowing it.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAnOrBelowTheTopLevelOfARelationKeepsTheCorrelationPredicate(): void
    {
        $sql = $this->applyFilters([
            'posts' => [
                'wrapper' => [
                    '$or' => [
                        'title' => ['$eq' => 'test'],
                        'id'    => ['$eq' => 1],
                    ],
                ],
            ],
        ])->toSql();

        self::assertStringContainsString('"users"."id" = "posts"."user_id" and (', $sql);
        self::assertStringNotContainsString('"users"."id" = "posts"."user_id" or', $sql);
    }

    /**
     * Test that a key sitting beside $or in a relation filter is applied rather
     * than discarded, so the existence check is the one the client asked for.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAKeyBesideAnOrInARelationFilterIsStillApplied(): void
    {
        $bindings = $this->applyFilters([
            'posts' => [
                '$or' => ['title' => ['$eq' => 'test']],
                'id'  => ['$eq' => 7],
            ],
        ])->getBindings();

        self::assertContains('test', $bindings);
        self::assertContains(7, $bindings, 'The key beside the $or must reach the query.');
    }

    /**
     * Assert that the given filters are rejected on cost, carrying the cap that
     * rejected them, the position within the document, and both sides of the
     * comparison.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $reason
     * @param  string  $pointer
     * @param  int  $limit
     * @param  int  $actual
     * @return void
     */
    private function assertRejectedForCost(array $filters, string $reason, string $pointer, int $limit, int $actual): void
    {
        try {
            $this->applyFilters($filters);

            self::fail('Expected a rejection for the "' . $reason . '" cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => 'filters',
                'pointer'   => $pointer,
                'reason'    => $reason,
                'limit'     => $limit,
                'actual'    => $actual,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Build a query surface for the User root model.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Enums\Capability>  $filterable
     * @param  array<int, string>  $sortable
     * @param  array<int, string>  $relations
     * @param  array<string, string>  $resourceMap
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function declaredSurface(array $filterable = [], array $sortable = [], array $relations = [], array $resourceMap = []): QuerySurface
    {
        return new QuerySurface($filterable, $sortable, $relations, new User, $resourceMap);
    }

    /**
     * Apply filters using the FilterApplier and return the resulting query
     * builder.
     *
     * @param  array<string, mixed>|null  $filters
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyFilters(?array $filters): Builder
    {
        return $this->applier->apply((new User)->newQuery(), $filters, $this->operatorRegistry, $this->surface());
    }

    /**
     * Build the query surface these mechanics tests filter against, declaring
     * the root columns and relation they use plus the related post resource
     * that governs the nested hops.
     *
     * One column is declared per capability, so every operator the dispatch
     * tests drive has a column whose declaration answers it.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function surface(): QuerySurface
    {
        return $this->declaredSurface(
            filterable : [
                'name'            => Capability::EXACT,
                'email'           => Capability::EXACT,
                'id'              => Capability::RANGE,
                'organization_id' => Capability::EXACT,
                'status'          => Capability::ENUM,
                'context'         => Capability::DOCUMENT,
            ],
            relations  : ['posts'],
            resourceMap: [Post::class => DeepTraversalPostResource::class],
        );
    }

    /**
     * Assert that applying the filters rejects the operator on the given
     * column, naming both and listing what the column does accept, and that no
     * clause reached the builder before the refusal.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $column
     * @param  string  $operator
     * @param  string  $accepts
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function assertRejectsOperator(array $filters, string $column, string $operator, string $accepts): void
    {
        $query = (new User)->newQuery();

        try {
            $this->applier->apply($query, $filters, $this->operatorRegistry, $this->surface());
            self::fail('Expected a ValidationException for the "' . $operator . '" operator on the "' . $column . '" filter key.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "' . $operator . '" operator is not permitted on the "' . $column . '" key for this resource, which accepts ' . $accepts . '.'],
                $exception->errors()['filters.' . $column . '.' . $operator] ?? [],
            );
            self::assertEmpty($query->getQuery()->wheres);
        }
    }

    /**
     * Assert that applying the filters rejects the given key with a named
     * validation error.
     *
     * @param  array<string, mixed>  $filters
     * @param  string  $key
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function assertRejectsKey(array $filters, string $key): void
    {
        try {
            $this->applyFilters($filters);
            self::fail('Expected a ValidationException for the "' . $key . '" filter key.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "' . $key . '" key is not a permitted query parameter for this resource.'],
                $exception->errors()['filters.' . $key] ?? [],
            );
        }
    }

    /**
     * Return the filter clauses a relation scope carries, having asserted the
     * scope groups them rather than emitting them beside its correlation
     * predicate.
     *
     * @param  \Illuminate\Database\Query\Builder  $subQuery
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function relationGroupWheres(mixed $subQuery): Collection
    {
        /** @var array{boolean: string, query: \Illuminate\Database\Query\Builder}|null $group */
        $group = collect($subQuery->wheres)->first(static fn (array $where): bool => $where['type'] === 'Nested');

        self::assertNotNull($group, 'The relation scope must group its filters.');
        self::assertSame('and', $group['boolean'], 'The filter group must be ANDed with the correlation predicate.');

        return collect($group['query']->wheres);
    }
}
