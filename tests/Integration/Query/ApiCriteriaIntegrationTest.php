<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\LimitApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Integration tests for ApiCriteria with a real database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(FilterContext::class)]
#[CoversClass(OrderApplier::class)]
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(LimitApplier::class)]
final class ApiCriteriaIntegrationTest extends TestCase
{
    /**
     * Seed users and posts for integration tests.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedData();
    }

    /**
     * Test filtering by simple field value.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFilteringBySimpleFieldValue(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => 'Alice'])]);

        $results = $this->makeCriteria()->apply(new User)->get();

        self::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        self::assertSame('Alice', $first->name);
    }

    /**
     * Test filtering with $eq operator.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFilteringWithEqOperator(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => ['$eq' => 'Bob']])]);

        $results = $this->makeCriteria()->apply(new User)->get();

        self::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        self::assertSame('Bob', $first->name);
    }

    /**
     * Test filtering with $neq operator.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFilteringWithNeqOperator(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => ['$neq' => 'Alice']])]);

        $results = $this->makeCriteria()->apply(new User)->get();

        self::assertTrue($results->pluck('name')->doesntContain('Alice'));
        self::assertGreaterThan(0, $results->count());
    }

    /**
     * Test filtering with $like operator.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFilteringWithLikeOperator(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => ['$like' => 'Ali']])]);

        $results = $this->makeCriteria()->apply(new User)->get();

        self::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        self::assertSame('Alice', $first->name);
    }

    /**
     * Test filtering with $in operator.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFilteringWithInOperator(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => ['$in' => ['Alice', 'Charlie']]])]);

        $results = $this->makeCriteria()->apply(new User)->get();

        self::assertCount(2, $results);
        self::assertTrue($results->pluck('name')->contains('Alice'));
        self::assertTrue($results->pluck('name')->contains('Charlie'));
    }

    /**
     * Test filtering with $null operator.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFilteringWithNullOperator(): void
    {
        User::where('name', 'Alice')->update(['organization_id' => 1]);

        $this->parseQuery(['filters' => json_encode(['organization_id' => ['$null' => true]])]);

        $results = $this->makeCriteria()->apply(new User)->get();

        self::assertSame(['Bob', 'Charlie'], $results->pluck('name')->all());
    }

    /**
     * Test filtering with $notNull operator.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFilteringWithNotNullOperator(): void
    {
        User::where('name', 'Alice')->update(['organization_id' => 1]);

        $this->parseQuery(['filters' => json_encode(['organization_id' => ['$notNull' => true]])]);

        $results = $this->makeCriteria()->apply(new User)->get();

        self::assertSame(['Alice'], $results->pluck('name')->all());
    }

    /**
     * Test filtering with relation ($has operator).
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFilteringWithHasRelation(): void
    {
        $this->parseQuery(['filters' => json_encode(['$has' => 'posts'])]);

        $results = $this->makeCriteria()->apply(new User)->get();

        // Only Alice and Bob have posts
        self::assertCount(2, $results);
    }

    /**
     * Test filtering with relation ($hasnt operator).
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFilteringWithHasntRelation(): void
    {
        $this->parseQuery(['filters' => json_encode(['$hasnt' => 'posts'])]);

        $results = $this->makeCriteria()->apply(new User)->get();

        // Charlie has no posts
        self::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        self::assertSame('Charlie', $first->name);
    }

    /**
     * Test that a root-level $or cannot escape a constraint the caller applies
     * to the query before the criteria run.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRootOrCannotEscapeACallerAppliedConstraint(): void
    {
        $this->parseQuery([
            'filters' => json_encode([
                '$or' => [
                    'name'  => 'Alice',
                    'email' => 'charlie@example.com',
                ],
            ]),
        ]);

        $results = $this->makeCriteria()->apply(User::query()->where('status', 'active'))->get();

        self::assertSame(['Alice'], $results->pluck('name')->all());
    }

    /**
     * Test ordering by column ascending.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testOrderingByColumnAsc(): void
    {
        $this->parseQuery(['order' => 'name:asc']);

        $results = $this->makeCriteria()->apply(new User)->get();

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        /** @var \Tests\Fixtures\Models\User $last */
        $last = $results->last();

        self::assertSame('Alice', $first->name);
        self::assertSame('Charlie', $last->name);
    }

    /**
     * Test ordering by column descending.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testOrderingByColumnDesc(): void
    {
        $this->parseQuery(['order' => 'name:desc']);

        $results = $this->makeCriteria()->apply(new User)->get();

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        /** @var \Tests\Fixtures\Models\User $last */
        $last = $results->last();

        self::assertSame('Charlie', $first->name);
        self::assertSame('Alice', $last->name);
    }

    /**
     * Test ordering by random.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testOrderingByRandom(): void
    {
        Config::set('api-toolkit.repositories.allow_random_order', true);

        $this->parseQuery(['order' => 'random']);

        $results = $this->makeCriteria()->apply(new User)->get();

        // Cannot assert order, but we can assert the count is correct
        self::assertCount(3, $results);
    }

    /**
     * Test that limit is applied.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testLimitIsApplied(): void
    {
        $this->parseQuery(['limit' => '2']);

        $results = $this->makeCriteria()->apply(new User)->get();

        self::assertCount(2, $results);
    }

    /**
     * Test combined filters, order, and limit.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testCombinedFiltersOrderAndLimit(): void
    {
        $this->parseQuery([
            'filters' => json_encode(['$has' => 'posts']),
            'order'   => 'name:desc',
            'limit'   => '1',
        ]);

        $results = $this->makeCriteria()->apply(new User)->get();

        self::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        self::assertSame('Bob', $first->name);
    }

    /**
     * Test that a relation filter reached through an $or group narrows to the
     * rows that own a matching related record, rather than matching every row
     * as soon as the relation holds one anywhere in the table.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRelationFilterUnderOrMatchesOnlyTheOwningRows(): void
    {
        Config::set('api-toolkit.resources.resource_map', [Post::class => PostResource::class]);

        $this->parseQuery([
            'filters' => json_encode([
                '$or' => [
                    'nested' => ['posts' => ['title' => 'Alice Post']],
                    'name'   => 'Charlie',
                ],
            ]),
        ]);

        $results = $this->makeCriteria()->apply(new User)->get();

        self::assertSame(['Alice', 'Charlie'], $results->pluck('name')->sort()->values()->all());
    }

    /**
     * Parse query parameters through the ApiQuery facade.
     *
     * @param  array<string, string>  $params
     * @return void
     */
    private function parseQuery(array $params): void
    {
        $request = Request::create('/test', HttpMethod::GET->getVerb(), $params);

        ApiQuery::parse($request);
    }

    /**
     * Resolve a fresh ApiCriteria instance from the container.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria
     */
    private function makeCriteria(): ApiCriteria
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);

        return $criteria->usingResource(UserResource::class);
    }

    /**
     * Seed the database with test data.
     *
     * @return void
     */
    private function seedData(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);

        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'status' => 'inactive']);

        Post::create(['user_id' => $alice->id, 'title' => 'Alice Post', 'body' => 'Content', 'published' => true]);
        Post::create(['user_id' => $bob->id, 'title' => 'Bob Post', 'body' => 'Content', 'published' => false]);
    }
}
