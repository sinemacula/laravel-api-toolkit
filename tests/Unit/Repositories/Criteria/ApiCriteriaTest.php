<?php

namespace Tests\Unit\Repositories\Criteria;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'filters' => json_encode(['name' => ['$like' => 'Ali']]),
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
                    'title' => ['$like' => 'test'],
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
