<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\LimitApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Log;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\LogResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Tests for the ApiCriteria class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1448")
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(FilterContext::class)]
#[CoversClass(OrderApplier::class)]
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(LimitApplier::class)]
final class ApiCriteriaTest extends TestCase
{
    /** @var string */
    private const string STUB_USER_FIELDS = 'id,name';

    /** @var string */
    private const string OPERATOR_EQUAL = '$eq';

    /** @var string */
    private const string OPERATOR_CONTAINS = '$contains';

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria */
    private ApiCriteria $criteria;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        // These assertions measure query shape and call counts; pin column
        // narrowing off so the on-by-default narrowing metadata pass cannot
        // skew them (narrowing behaviour has its own dedicated coverage).
        Config::set('api-toolkit.resources.narrow_columns', false);

        // These tests assert applier mechanics (operators, logical groups,
        // relations, ordering, limits), so they run against resources that
        // declare the surface they query rather than empty ones.
        Config::set('api-toolkit.resources.resource_map', [Post::class => PostResource::class]);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria       = $this->app->make(ApiCriteria::class);
        $this->criteria = $criteria->usingResource(UserResource::class);
    }

    /**
     * Test that apply with no filters, order, or limit returns an unmodified
     * query.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNoFiltersOrderOrLimitReturnsUnmodifiedQuery(): void
    {
        $this->parseRequest(new Request);

        $model = new User;
        $query = $this->criteria->apply($model);

        self::assertEmpty($query->getQuery()->wheres);
        self::assertEmpty($query->getQuery()->orders ?? []);
        self::assertNull($query->getQuery()->limit);
    }

    /**
     * Test that an empty filter set adds no group of its own, leaving a
     * caller-applied constraint as the only clause on the query.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testEmptyFilterSetEmitsNoGroup(): void
    {
        $this->parseRequest(new Request);

        $query = $this->metricFreeCriteria()->apply(User::query()->where('status', 'active'));

        self::assertSame('select * from "users" where "status" = ?', $this->sqlOf($query));
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
        $this->parseRequest(new Request([
            'filters' => json_encode(['name' => 'Alice']),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $this->filterGroupWheres($query);

        self::assertNotEmpty($wheres);
        self::assertSame('name', $wheres[0]['column']);
        self::assertSame('Alice', $wheres[0]['value']);
    }

    /**
     * Provide condition operator test cases, each paired with a column the
     * resource declared with a capability answering that operator.
     *
     * @return iterable<string, array{string, string, string, mixed, string}>
     */
    public static function conditionOperatorProvider(): iterable
    {
        yield '$eq operator' => ['$eq', 'name', '=', 'Alice', 'Basic'];
        yield '$neq operator' => ['$neq', 'status', '<>', 'active', 'Basic'];
        yield '$gt operator' => ['$gt', 'id', '>', '10', 'Basic'];
        yield '$lt operator' => ['$lt', 'id', '<', '10', 'Basic'];
        yield '$ge operator' => ['$ge', 'id', '>=', '10', 'Basic'];
        yield '$le operator' => ['$le', 'id', '<=', '10', 'Basic'];
    }

    /**
     * Test that apply with the $eq operator applies an equals condition.
     *
     * @param  string  $operator
     * @param  string  $column
     * @param  string  $expectedSqlOperator
     * @param  mixed  $value
     * @param  string  $expectedType
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('conditionOperatorProvider')]
    public function testApplyWithConditionOperator(string $operator, string $column, string $expectedSqlOperator, mixed $value, string $expectedType): void
    {
        $filter = [$column => [$operator => $value]];

        $this->parseRequest(new Request([
            'filters' => json_encode($filter),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $this->filterGroupWheres($query);

        self::assertNotEmpty($wheres);
        self::assertSame($expectedType, $wheres[0]['type']);
    }

    /**
     * Test that a search term is applied as its own group ahead of the filter
     * group, so both narrow the query rather than one replacing the other.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyAppliesTheSearchTermAlongsideTheFilters(): void
    {
        $this->parseRequest(new Request([
            'search'  => 'smith',
            'filters' => json_encode(['status' => [self::OPERATOR_EQUAL => 'active']]),
        ]));

        $query = $this->searchableCriteria()->apply(new User);

        $wheres = $query->getQuery()->wheres;

        self::assertCount(2, $wheres);
        self::assertSame('Nested', $wheres[0]['type']);
        self::assertSame('Nested', $wheres[1]['type']);
        self::assertSame(['%smith%', '%smith%', 'active'], $query->getQuery()->getBindings());
    }

    /**
     * Test that a request carrying no search term adds no search group.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithoutASearchTermAddsNoSearchGroup(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['status' => [self::OPERATOR_EQUAL => 'active']]),
        ]));

        $query = $this->searchableCriteria()->apply(new User);

        self::assertCount(1, $query->getQuery()->wheres);
        self::assertSame(['active'], $query->getQuery()->getBindings());
    }

    /**
     * Test that apply with $in operator uses whereIn.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithInOperatorUsesWhereIn(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['name' => ['$in' => ['Alice', 'Bob']]]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $this->filterGroupWheres($query);

        self::assertNotEmpty($wheres);
        self::assertSame('In', $wheres[0]['type']);
        self::assertSame(['Alice', 'Bob'], $wheres[0]['values']);
    }

    /**
     * Test that apply with $between operator uses whereBetween.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithBetweenOperatorUsesWhereBetween(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['id' => ['$between' => [1, 10]]]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $this->filterGroupWheres($query);

        self::assertNotEmpty($wheres);
        self::assertSame('between', $wheres[0]['type']);
    }

    /**
     * Test that apply with $null operator adds whereNull clause.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNullOperatorAddsWhereNull(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['organization_id' => ['$null' => true]]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $this->filterGroupWheres($query);

        self::assertNotEmpty($wheres);
        self::assertSame('Null', $wheres[0]['type']);
        self::assertSame('organization_id', $wheres[0]['column']);
    }

    /**
     * Test that apply with $notNull operator adds whereNotNull clause.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNotNullOperatorAddsWhereNotNull(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['organization_id' => ['$notNull' => true]]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $this->filterGroupWheres($query);

        self::assertNotEmpty($wheres);
        self::assertSame('NotNull', $wheres[0]['type']);
    }

    /**
     * Test that apply with $has relational operator adds whereHas.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithHasOperatorAddsWhereHas(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['$has' => ['posts']]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $this->filterGroupWheres($query);

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that apply with $hasnt relational operator adds whereDoesntHave.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithHasntOperatorAddsWhereDoesntHave(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['$hasnt' => ['posts']]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $this->filterGroupWheres($query);

        self::assertNotEmpty($wheres);
        self::assertSame('NotExists', $wheres[0]['type']);
    }

    /**
     * Test that apply with $or logical operator groups conditions.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithOrLogicalOperator(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode([
                '$or' => [
                    'name'  => 'Alice',
                    'email' => 'bob@example.com',
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        self::assertNotEmpty($wheres);
    }

    /**
     * Test that a root-level $or is grouped so it cannot escape a constraint
     * the caller applies to the query before the criteria run.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRootOrCannotEscapeACallerAppliedConstraint(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode([
                '$or' => [
                    'name'  => 'Alice',
                    'email' => 'charlie@example.com',
                ],
            ]),
        ]));

        $query = $this->metricFreeCriteria()->apply(User::query()->where('status', 'active'));

        self::assertSame(
            'select * from "users" where "status" = ? and (("name" = ? or "email" = ?))',
            $this->sqlOf($query),
        );

        self::assertSame(['active', 'Alice', 'charlie@example.com'], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that apply with $and logical operator groups conditions.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithAndLogicalOperator(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode([
                '$and' => [
                    'name'  => 'Alice',
                    'email' => 'alice@example.com',
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        self::assertNotEmpty($wheres);
    }

    /**
     * Test that apply with nested relation filters applies whereHas with nested
     * conditions.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithNestedRelationFilters(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode([
                'posts' => [
                    'title' => [self::OPERATOR_EQUAL => 'test'],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $this->filterGroupWheres($query);

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that apply with order applies orderBy to the query.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithOrderAppliesOrderBy(): void
    {
        $this->parseRequest(new Request([
            'order' => 'name:asc',
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $orders = $query->getQuery()->orders ?? [];

        self::assertNotEmpty($orders);
        self::assertSame('name', $orders[0]['column']);
        self::assertSame('asc', $orders[0]['direction']);
    }

    /**
     * Test that apply with 'random' order applies inRandomOrder once the
     * capability is enabled.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithRandomOrderAppliesInRandomOrder(): void
    {
        Config::set('api-toolkit.repositories.allow_random_order', true);

        $this->parseRequest(new Request([
            'order' => 'random',
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $orders = $query->getQuery()->orders ?? [];

        self::assertNotEmpty($orders);
        self::assertSame('RANDOM()', $orders[0]['sql'] ?? $orders[0]['column'] ?? '');
    }

    /**
     * Test that 'random' order is rejected while the capability is disabled, so
     * the most expensive sort is not reachable by default.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithRandomOrderIsRejectedWhileDisabled(): void
    {
        $this->parseRequest(new Request([
            'order' => 'random',
        ]));

        try {
            $this->criteria->apply(new User);
            self::fail('Expected a ValidationException for the disabled random order keyword.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "random" key is not a permitted query parameter for this resource.'],
                $exception->errors()['order.random'] ?? [],
            );
        }
    }

    /**
     * Test that apply with limit applies a query limit.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyWithLimitAppliesQueryLimit(): void
    {
        $this->parseRequest(new Request([
            'limit' => '5',
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        self::assertSame(5, $query->getQuery()->limit);
    }

    /**
     * Test that applyEagerLoading adds eager loads from the resource schema.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyEagerLoadingAddsEagerLoadsFromResource(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $this->parseRequest(new Request([
            'fields' => ['users' => 'id,name,organization'],
        ]));

        $this->criteria->usingResource(UserResource::class);

        $model = new User;
        $query = $this->criteria->apply($model);

        $eagerLoads = $query->getEagerLoads();

        self::assertNotEmpty($eagerLoads);
    }

    /**
     * Test that an undeclared column is rejected rather than dropped.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUndeclaredColumnsAreRejected(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['nonexistent_column' => 'value']),
        ]));

        try {
            $this->criteria->apply(new User);
            self::fail('Expected a ValidationException for an undeclared filter key.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['The "nonexistent_column" key is not a permitted query parameter for this resource.'],
                $exception->errors()['filters.nonexistent_column'] ?? [],
            );
        }
    }

    /**
     * Test that order with an invalid direction is ignored.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testOrderWithInvalidDirectionIsIgnored(): void
    {
        $this->parseRequest(new Request([
            'order' => 'name:invalid',
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $orders = $query->getQuery()->orders ?? [];

        self::assertEmpty($orders);
    }

    /**
     * Test that applyEagerLoading uses getAllFields when ':all' is requested.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyEagerLoadingUsesGetAllFieldsWhenAllRequested(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $this->parseRequest(new Request([
            'fields' => ['users' => ':all'],
        ]));

        $this->criteria->usingResource(UserResource::class);

        $model = new User;
        $query = $this->criteria->apply($model);

        self::assertNotNull($query->getQuery());
        self::assertInstanceOf(Builder::class, $query);
    }

    /**
     * Test that a condition operator inside a logical group is handled.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testConditionOperatorInsideLogicalGroupIsHandled(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['$or' => ['$eq' => 'anything']]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        self::assertInstanceOf(Builder::class, $query);
        self::assertIsArray($query->getQuery()->wheres);
    }

    /**
     * Test that a nested logical operator inside a logical group is handled.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNestedLogicalOperatorInsideLogicalGroupIsHandled(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode([
                '$and' => [
                    '$or' => [
                        'name'  => 'Alice',
                        'email' => 'alice@example.com',
                    ],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        self::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $or inside a relation filter creates a grouped orWhere.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testOrInsideRelationFilterCreatesOrWhereGroup(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode([
                'posts' => [
                    '$or' => [
                        'title' => [self::OPERATOR_EQUAL => 'test'],
                    ],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        self::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that a named $has relation with conditions applies whereHas with
     * nested constraints.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testHasFilterWithNamedRelationAndConditions(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode([
                '$has' => [
                    'posts' => ['title' => [self::OPERATOR_EQUAL => 'test']],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $this->filterGroupWheres($query);

        self::assertNotEmpty($wheres);
        self::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that $hasnt with a named relation and conditions applies
     * whereDoesntHave.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testHasntFilterWithNamedRelationAndConditions(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode([
                '$hasnt' => [
                    'posts' => ['title' => [self::OPERATOR_EQUAL => 'test']],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $this->filterGroupWheres($query);

        self::assertNotEmpty($wheres);
        self::assertSame('NotExists', $wheres[0]['type']);
    }

    /**
     * Test that $or combined with $has uses orWhereHas.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testOrWithHasOperatorUsesOrWhereHas(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode([
                '$or' => [
                    '$has' => ['posts'],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        self::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $between with a single-element array is ignored.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testBetweenWithWrongArraySizeIsIgnored(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['id' => ['$between' => [1]]]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        self::assertEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $contains with an array value uses whereJsonContains.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testContainsWithArrayValueUsesWhereJsonContains(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['context' => [self::OPERATOR_CONTAINS => ['php']]]),
        ]));

        $query = $this->documentCriteria()->apply(new Log);

        self::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $contains with a comma-separated string creates multiple
     * whereJsonContains conditions.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testContainsWithCommaSeparatedStringCreatesMultipleConditions(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['context' => [self::OPERATOR_CONTAINS => 'php,rust']]),
        ]));

        $query = $this->documentCriteria()->apply(new Log);

        self::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $contains with a plain scalar string uses whereJsonContains.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testContainsWithPlainStringUsesWhereJsonContains(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['context' => [self::OPERATOR_CONTAINS => 'php']]),
        ]));

        $query = $this->documentCriteria()->apply(new Log);

        self::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $notNull with $or logical operator uses orWhereNotNull.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNotNullWithOrLogicalOperatorUsesOrWhereNotNull(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode([
                '$or' => [
                    'organization_id' => ['$notNull' => true],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        self::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $contains with null exercises the isValidJson null path and the
     * defensive catch inside applyJsonContains.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testContainsWithNullValueIsHandledGracefully(): void
    {
        $this->parseRequest(new Request([
            'filters' => json_encode(['context' => [self::OPERATOR_CONTAINS => null]]),
        ]));

        $query = $this->documentCriteria()->apply(new Log);

        self::assertIsArray($query->getQuery()->wheres);
    }

    /**
     * Test that applyEagerLoading returns early when fields resolve to an empty
     * array.
     *
     * @SuppressWarnings("php:S2014")
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyEagerLoadingReturnsEarlyWhenFieldsAreEmpty(): void
    {
        $resourceClass = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'empty_res';

            /** @var array<int, string> */
            protected static array $default = [];

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [];
            }
        };

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);
        $criteria->usingResource($resourceClass::class);

        $this->parseRequest(new Request);

        $model = new User;
        $query = $criteria->apply($model);

        self::assertEmpty($query->getEagerLoads());
    }

    /**
     * Test that applyEagerLoading calls getResourceType on the metadata
     * provider.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyEagerLoadingUsesMetadataProviderForResourceType(): void
    {
        assert($this->app !== null);

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->expects(self::once())
            ->method('getResourceType')
            ->with(UserResource::class)
            ->willReturn('users');

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $this->app->instance(ResourceMetadataProvider::class, $provider);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);
        $criteria->usingResource(UserResource::class);

        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $criteria->apply(new User);
    }

    /**
     * Test that applyEagerLoading calls resolveFields on the metadata provider.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyEagerLoadingUsesMetadataProviderForFieldResolution(): void
    {
        assert($this->app !== null);

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('getResourceType')
            ->willReturn('users');

        $provider->expects(self::once())
            ->method('resolveFields')
            ->with(UserResource::class)
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $this->app->instance(ResourceMetadataProvider::class, $provider);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);
        $criteria->usingResource(UserResource::class);

        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $criteria->apply(new User);
    }

    /**
     * Test that applyEagerLoading calls eagerLoadMapFor on the metadata
     * provider.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyEagerLoadingUsesMetadataProviderForEagerLoadMap(): void
    {
        assert($this->app !== null);

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('getResourceType')
            ->willReturn('users');

        $provider->method('resolveFields')
            ->willReturn(['id', 'name', 'organization']);

        $provider->expects(self::once())
            ->method('eagerLoadMapFor')
            ->with(UserResource::class, ['id', 'name', 'organization'])
            ->willReturn(['organization' => fn () => null]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $this->app->instance(ResourceMetadataProvider::class, $provider);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);
        $criteria->usingResource(UserResource::class);

        $this->parseRequest(new Request([
            'fields' => ['users' => 'id,name,organization'],
        ]));

        $criteria->apply(new User);
    }

    /**
     * Test that applyEagerLoading calls eagerLoadCountsFor on the metadata
     * provider.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyEagerLoadingUsesMetadataProviderForCountMap(): void
    {
        assert($this->app !== null);

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('getResourceType')
            ->willReturn('users');

        $provider->method('resolveFields')
            ->willReturn(['id', 'name']);

        $provider->method('eagerLoadMapFor')
            ->willReturn([]);

        $provider->expects(self::once())
            ->method('eagerLoadCountsFor')
            ->with(UserResource::class, [])
            ->willReturn([]);

        $this->app->instance(ResourceMetadataProvider::class, $provider);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);
        $criteria->usingResource(UserResource::class);

        $this->parseRequest(new Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $criteria->apply(new User);
    }

    /**
     * Test that getResourceType returns null when the resolved resource class
     * is not an ApiResource subclass.
     *
     * @return void
     */
    public function testGetResourceTypeReturnsNullForNonApiResourceClass(): void
    {
        $metadataProvider = self::createStub(ResourceMetadataProvider::class);
        $metadataProvider->method('getResourceType')->willReturn('users');

        assert($this->app !== null);

        $criteria = new ApiCriteria(
            new Request,
            $metadataProvider,
            self::createStub(SchemaIntrospectionProvider::class),
            new OperatorRegistry,
            new SearchDriverRegistry,
            $this->app->make(MetadataCacheWriter::class),
        );

        $criteria->usingResource(\stdClass::class);

        $reflection = new \ReflectionMethod($criteria, 'getResourceType');

        self::assertNull($reflection->invoke($criteria, new User));
    }

    /**
     * Test that a resolved resource that is not an ApiResource subclass yields
     * an empty query surface rather than being compiled as a schema.
     *
     * @return void
     */
    public function testBuildQuerySurfaceYieldsEmptySurfaceForNonApiResource(): void
    {
        $this->criteria->usingResource(\stdClass::class);

        $method  = new \ReflectionMethod($this->criteria, 'buildQuerySurface');
        $surface = $method->invoke($this->criteria, new User);

        self::assertInstanceOf(QuerySurface::class, $surface);

        $property = new \ReflectionProperty($surface, 'filterableColumns');

        self::assertSame([], $property->getValue($surface));
    }

    /**
     * Test that the flat cost caps are enforced before the query is built, so
     * an over-cost request never reaches the appliers.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testApplyEnforcesTheFlatCostCapsBeforeBuildingTheQuery(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 10);

        $this->parseRequest(Request::create('/test', HttpMethod::GET->getVerb(), ['page' => '11']));

        try {
            $this->criteria->apply(new User);

            self::fail('Expected a rejection for a page beyond the offset cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => 'page',
                'pointer'   => '',
                'reason'    => QueryCostLimits::MAX_OFFSET,
                'limit'     => 10,
                'actual'    => 11,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Resolve the where clauses nested inside the grouped filter expression.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @return array<int, mixed>
     */
    private function filterGroupWheres(BuilderContract $query): array
    {
        $wheres = $query->getQuery()->wheres;

        self::assertSame('Nested', $wheres[0]['type']);

        return $wheres[0]['query']->wheres;
    }

    /**
     * Build a criteria bound to a resource declaring a search surface, with a
     * driver registered for the connection under test.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria
     */
    private function searchableCriteria(): ApiCriteria
    {
        assert($this->app !== null);

        $connection = (new User)->getConnection()->getDriverName();

        $this->app->make(SearchDriverRegistry::class)->override($connection, new PatternSearchDriver);

        Config::set('api-toolkit.search.unverified_connections', [$connection]);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);

        return $criteria->usingResource(SearchableFilterableUserResource::class);
    }

    /**
     * Build a criteria bound to a resource that declares no default metrics, so
     * an asserted SQL string is the query the filters built and nothing more.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria
     */
    private function metricFreeCriteria(): ApiCriteria
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);

        return $criteria->usingResource(FilterableUserResource::class);
    }

    /**
     * Build a criteria bound to a resource declaring a document column, the
     * only capability the containment operator is served from.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria
     */
    private function documentCriteria(): ApiCriteria
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);

        return $criteria->usingResource(LogResource::class);
    }

    /**
     * Compile the query to its SQL string for assertion.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @return string
     */
    private function sqlOf(BuilderContract $query): string
    {
        return $query->toSql(); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Resolve the API query parser and parse the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function parseRequest(Request $request): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');
        $parser->parse($request);
    }
}
