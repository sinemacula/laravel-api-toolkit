<?php

namespace Tests\Unit\Repositories\Criteria;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
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
class ApiCriteriaTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /** @var string */
    private const STUB_USER_FIELDS = 'id,name';

    /** @var string */
    private const string OPERATOR_LIKE = '$like';

    /** @var string */
    private const string OPERATOR_CONTAINS = '$contains';

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria */
    private ApiCriteria $criteria;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria       = $this->app->make(ApiCriteria::class);
        $this->criteria = $criteria;
    }

    /**
     * Test that apply with no filters, order, or limit returns an unmodified
     * query.
     *
     * @return void
     */
    public function testApplyWithNoFiltersOrderOrLimitReturnsUnmodifiedQuery(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request);

        $model = new User;
        $query = $this->criteria->apply($model);

        static::assertEmpty($query->getQuery()->wheres);
        static::assertEmpty($query->getQuery()->orders ?? []);
        static::assertNull($query->getQuery()->limit);
    }

    /**
     * Test that apply with a simple filter applies a where clause.
     *
     * @return void
     */
    public function testApplyWithSimpleFilterAppliesWhereClause(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['name' => 'Alice']),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('name', $wheres[0]['column']);
        static::assertSame('Alice', $wheres[0]['value']);
    }

    /**
     * Test that apply with the $eq operator applies an equals condition.
     *
     * @param  string  $operator
     * @param  string  $expectedSqlOperator
     * @param  mixed  $value
     * @param  string  $expectedType
     * @return void
     */
    #[DataProvider('conditionOperatorProvider')]
    public function testApplyWithConditionOperator(string $operator, string $expectedSqlOperator, mixed $value, string $expectedType): void
    {
        $filter = ['name' => [$operator => $value]];

        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode($filter),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame($expectedType, $wheres[0]['type']);
    }

    /**
     * Provide condition operator test cases.
     *
     * @return iterable<string, array{string, string, mixed, string}>
     */
    public static function conditionOperatorProvider(): iterable
    {
        yield '$eq operator' => ['$eq', '=', 'Alice', 'Basic'];
        yield '$neq operator' => ['$neq', '<>', 'Alice', 'Basic'];
        yield '$gt operator' => ['$gt', '>', '10', 'Basic'];
        yield '$lt operator' => ['$lt', '<', '10', 'Basic'];
        yield '$ge operator' => ['$ge', '>=', '10', 'Basic'];
        yield '$le operator' => ['$le', '<=', '10', 'Basic'];
    }

    /**
     * Test that apply with $like operator wraps value with percent signs.
     *
     * @return void
     */
    public function testApplyWithLikeOperatorWrapsValueWithPercent(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['name' => [self::OPERATOR_LIKE => 'Ali']]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('%Ali%', $wheres[0]['value']);
    }

    /**
     * Test that apply with $in operator uses whereIn.
     *
     * @return void
     */
    public function testApplyWithInOperatorUsesWhereIn(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['name' => ['$in' => ['Alice', 'Bob']]]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('In', $wheres[0]['type']);
        static::assertSame(['Alice', 'Bob'], $wheres[0]['values']);
    }

    /**
     * Test that apply with $between operator uses whereBetween.
     *
     * @return void
     */
    public function testApplyWithBetweenOperatorUsesWhereBetween(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['id' => ['$between' => [1, 10]]]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('between', $wheres[0]['type']);
    }

    /**
     * Test that apply with $null operator adds whereNull clause.
     *
     * @return void
     */
    public function testApplyWithNullOperatorAddsWhereNull(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['organization_id' => ['$null' => true]]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('Null', $wheres[0]['type']);
        static::assertSame('organization_id', $wheres[0]['column']);
    }

    /**
     * Test that apply with $notNull operator adds whereNotNull clause.
     *
     * @return void
     */
    public function testApplyWithNotNullOperatorAddsWhereNotNull(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['organization_id' => ['$notNull' => true]]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('NotNull', $wheres[0]['type']);
    }

    /**
     * Test that apply with $has relational operator adds whereHas.
     *
     * @return void
     */
    public function testApplyWithHasOperatorAddsWhereHas(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['$has' => ['posts']]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that apply with $hasnt relational operator adds whereDoesntHave.
     *
     * @return void
     */
    public function testApplyWithHasntOperatorAddsWhereDoesntHave(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['$hasnt' => ['posts']]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('NotExists', $wheres[0]['type']);
    }

    /**
     * Test that apply with $or logical operator groups conditions.
     *
     * @return void
     */
    public function testApplyWithOrLogicalOperator(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
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

        static::assertNotEmpty($wheres);
    }

    /**
     * Test that apply with $and logical operator groups conditions.
     *
     * @return void
     */
    public function testApplyWithAndLogicalOperator(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
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

        static::assertNotEmpty($wheres);
    }

    /**
     * Test that apply with nested relation filters applies whereHas with
     * nested conditions.
     *
     * @return void
     */
    public function testApplyWithNestedRelationFilters(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode([
                'posts' => [
                    'title' => [self::OPERATOR_LIKE => 'test'],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that apply with order applies orderBy to the query.
     *
     * @return void
     */
    public function testApplyWithOrderAppliesOrderBy(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'order' => 'name:asc',
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $orders = $query->getQuery()->orders ?? [];

        static::assertNotEmpty($orders);
        static::assertSame('name', $orders[0]['column']);
        static::assertSame('asc', $orders[0]['direction']);
    }

    /**
     * Test that apply with 'random' order applies inRandomOrder.
     *
     * @return void
     */
    public function testApplyWithRandomOrderAppliesInRandomOrder(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'order' => 'random',
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $orders = $query->getQuery()->orders ?? [];

        static::assertNotEmpty($orders);
        static::assertSame('RANDOM()', $orders[0]['sql'] ?? $orders[0]['column'] ?? '');
    }

    /**
     * Test that apply with limit applies a query limit.
     *
     * @return void
     */
    public function testApplyWithLimitAppliesQueryLimit(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'limit' => '5',
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        static::assertSame(5, $query->getQuery()->limit);
    }

    /**
     * Test that applyEagerLoading adds eager loads from the resource schema.
     *
     * @return void
     */
    public function testApplyEagerLoadingAddsEagerLoadsFromResource(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $this->parseRequest(new \Illuminate\Http\Request([
            'fields' => ['users' => 'id,name,organization'],
        ]));

        $this->criteria->usingResource(UserResource::class);

        $model = new User;
        $query = $this->criteria->apply($model);

        $eagerLoads = $query->getEagerLoads();

        static::assertNotEmpty($eagerLoads);
    }

    /**
     * Test that searchable exclusions from config are respected.
     *
     * @return void
     */
    public function testSearchableExclusionsFromConfigAreRespected(): void
    {
        Config::set('api-toolkit.repositories.searchable_exclusions', ['password']);

        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['password' => 'secret']),
        ]));

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);

        $model = new User;
        $query = $criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertEmpty($wheres);
    }

    /**
     * Test that invalid or unsearchable columns are ignored.
     *
     * @return void
     */
    public function testInvalidColumnsAreIgnored(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['nonexistent_column' => 'value']),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertEmpty($wheres);
    }

    /**
     * Test that order with an invalid direction is ignored.
     *
     * @return void
     */
    public function testOrderWithInvalidDirectionIsIgnored(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'order' => 'name:invalid',
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $orders = $query->getQuery()->orders ?? [];

        static::assertEmpty($orders);
    }

    /**
     * Test that applyEagerLoading uses getAllFields when ':all' is requested.
     *
     * @return void
     */
    public function testApplyEagerLoadingUsesGetAllFieldsWhenAllRequested(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $this->parseRequest(new \Illuminate\Http\Request([
            'fields' => ['users' => ':all'],
        ]));

        $this->criteria->usingResource(UserResource::class);

        $model = new User;
        $query = $this->criteria->apply($model);

        static::assertNotNull($query);
    }

    /**
     * Test that a condition operator inside a logical group is handled.
     *
     * @return void
     */
    public function testConditionOperatorInsideLogicalGroupIsHandled(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['$or' => ['$eq' => 'anything']]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        static::assertNotNull($query);
    }

    /**
     * Test that a nested logical operator inside a logical group is handled.
     *
     * @return void
     */
    public function testNestedLogicalOperatorInsideLogicalGroupIsHandled(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
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

        static::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $or inside a relation filter creates a grouped orWhere.
     *
     * @return void
     */
    public function testOrInsideRelationFilterCreatesOrWhereGroup(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode([
                'posts' => [
                    '$or' => [
                        'title' => [self::OPERATOR_LIKE => 'test'],
                    ],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        static::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that a named $has relation with conditions applies whereHas with
     * nested constraints.
     *
     * @return void
     */
    public function testHasFilterWithNamedRelationAndConditions(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode([
                '$has' => [
                    'posts' => ['title' => [self::OPERATOR_LIKE => 'test']],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('Exists', $wheres[0]['type']);
    }

    /**
     * Test that $hasnt with a named relation and conditions applies
     * whereDoesntHave.
     *
     * @return void
     */
    public function testHasntFilterWithNamedRelationAndConditions(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode([
                '$hasnt' => [
                    'posts' => ['title' => [self::OPERATOR_LIKE => 'test']],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('NotExists', $wheres[0]['type']);
    }

    /**
     * Test that $or combined with $has uses orWhereHas.
     *
     * @return void
     */
    public function testOrWithHasOperatorUsesOrWhereHas(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode([
                '$or' => [
                    '$has' => ['posts'],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        static::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $between with a single-element array is ignored.
     *
     * @return void
     */
    public function testBetweenWithWrongArraySizeIsIgnored(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['id' => ['$between' => [1]]]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        static::assertEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $contains with an array value uses whereJsonContains.
     *
     * @return void
     */
    public function testContainsWithArrayValueUsesWhereJsonContains(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['name' => [self::OPERATOR_CONTAINS => ['Alice']]]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        static::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $contains with a comma-separated string creates multiple
     * whereJsonContains conditions.
     *
     * @return void
     */
    public function testContainsWithCommaSeparatedStringCreatesMultipleConditions(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['name' => [self::OPERATOR_CONTAINS => 'Alice,Bob']]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        static::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $contains with a plain scalar string uses whereJsonContains.
     *
     * @return void
     */
    public function testContainsWithPlainStringUsesWhereJsonContains(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['name' => [self::OPERATOR_CONTAINS => 'Alice']]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        static::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that a table-specific searchable exclusion is respected.
     *
     * @return void
     */
    public function testTableSpecificSearchableExclusionIsRespected(): void
    {
        Config::set('api-toolkit.repositories.searchable_exclusions', ['users.password']);

        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['password' => 'secret']),
        ]));

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);

        $model = new User;
        $query = $criteria->apply($model);

        static::assertEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $notNull with $or logical operator uses orWhereNotNull.
     *
     * @return void
     */
    public function testNotNullWithOrLogicalOperatorUsesOrWhereNotNull(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode([
                '$or' => [
                    'organization_id' => ['$notNull' => true],
                ],
            ]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        static::assertNotEmpty($query->getQuery()->wheres);
    }

    /**
     * Test that $contains with null exercises the isValidJson null path and
     * the defensive catch inside applyJsonContains.
     *
     * @return void
     */
    public function testContainsWithNullValueIsHandledGracefully(): void
    {
        $this->parseRequest(new \Illuminate\Http\Request([
            'filters' => json_encode(['name' => [self::OPERATOR_CONTAINS => null]]),
        ]));

        $model = new User;
        $query = $this->criteria->apply($model);

        static::assertNotNull($query);
    }

    /**
     * Test that applyEagerLoading returns early when fields resolve to an
     * empty array.
     *
     * @SuppressWarnings("php:S2014")
     *
     * @return void
     */
    public function testApplyEagerLoadingReturnsEarlyWhenFieldsAreEmpty(): void
    {
        $resource_class = new class (null) extends \SineMacula\ApiToolkit\Http\Resources\ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'empty_res';

            /** @var array<int, string> */
            protected static array $default = [];

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            public static function schema(): array
            {
                return [];
            }
        };

        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);
        $criteria->usingResource($resource_class::class);

        $this->parseRequest(new \Illuminate\Http\Request);

        $model = new User;
        $query = $criteria->apply($model);

        static::assertEmpty($query->getEagerLoads());
    }

    /**
     * Test that isRelation returns false and catches the exception when the
     * relation method throws on invocation.
     *
     * @return void
     */
    public function testIsRelationReturnsFalseWhenRelationMethodThrows(): void
    {
        $throwingModel = new class extends \Illuminate\Database\Eloquent\Model {
            /** @var string|null */
            protected $table = 'users';

            /**
             * A method that exists and is callable but throws when invoked.
             *
             * @return never
             */
            public function brokenRelation(): never
            {
                throw new \UnexpectedValueException('intentional test error');
            }
        };

        $result = $this->invokeMethod($this->criteria, 'isRelation', 'brokenRelation', $throwingModel);

        static::assertFalse($result);
    }

    /**
     * Test that applyEagerLoading calls getResourceType on the metadata
     * provider.
     *
     * @return void
     */
    public function testApplyEagerLoadingUsesMetadataProviderForResourceType(): void
    {
        assert($this->app !== null);

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->expects(static::once())
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

        $this->parseRequest(new \Illuminate\Http\Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $criteria->apply(new User);
    }

    /**
     * Test that applyEagerLoading calls resolveFields on the metadata
     * provider.
     *
     * @return void
     */
    public function testApplyEagerLoadingUsesMetadataProviderForFieldResolution(): void
    {
        assert($this->app !== null);

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('getResourceType')
            ->willReturn('users');

        $provider->expects(static::once())
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

        $this->parseRequest(new \Illuminate\Http\Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $criteria->apply(new User);
    }

    /**
     * Test that applyEagerLoading calls eagerLoadMapFor on the metadata
     * provider.
     *
     * @return void
     */
    public function testApplyEagerLoadingUsesMetadataProviderForEagerLoadMap(): void
    {
        assert($this->app !== null);

        $provider = $this->createMock(ResourceMetadataProvider::class);

        $provider->method('getResourceType')
            ->willReturn('users');

        $provider->method('resolveFields')
            ->willReturn(['id', 'name', 'organization']);

        $provider->expects(static::once())
            ->method('eagerLoadMapFor')
            ->with(UserResource::class, ['id', 'name', 'organization'])
            ->willReturn(['organization' => fn () => null]);

        $provider->method('eagerLoadCountsFor')
            ->willReturn([]);

        $this->app->instance(ResourceMetadataProvider::class, $provider);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);
        $criteria->usingResource(UserResource::class);

        $this->parseRequest(new \Illuminate\Http\Request([
            'fields' => ['users' => 'id,name,organization'],
        ]));

        $criteria->apply(new User);
    }

    /**
     * Test that applyEagerLoading calls eagerLoadCountsFor on the metadata
     * provider.
     *
     * @return void
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

        $provider->expects(static::once())
            ->method('eagerLoadCountsFor')
            ->with(UserResource::class, [])
            ->willReturn([]);

        $this->app->instance(ResourceMetadataProvider::class, $provider);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);
        $criteria->usingResource(UserResource::class);

        $this->parseRequest(new \Illuminate\Http\Request([
            'fields' => ['users' => self::STUB_USER_FIELDS],
        ]));

        $criteria->apply(new User);
    }

    /**
     * Resolve the API query parser and parse the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    private function parseRequest(\Illuminate\Http\Request $request): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\ApiQueryParser $parser */
        $parser = $this->app->make('api.query');
        $parser->parse($request);
    }
}
