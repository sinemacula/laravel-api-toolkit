<?php

namespace Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
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
class ApiCriteriaIntegrationTest extends TestCase
{
    /**
     * Seed users and posts for integration tests.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedData();
    }

    /**
     * Test filtering by simple field value.
     *
     * @return void
     */
    public function testFilteringBySimpleFieldValue(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => 'Alice'])]);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        static::assertCount(1, $results);
        static::assertSame('Alice', $results->first()->name);
    }

    /**
     * Test filtering with $eq operator.
     *
     * @return void
     */
    public function testFilteringWithEqOperator(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => ['$eq' => 'Bob']])]);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        static::assertCount(1, $results);
        static::assertSame('Bob', $results->first()->name);
    }

    /**
     * Test filtering with $neq operator.
     *
     * @return void
     */
    public function testFilteringWithNeqOperator(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => ['$neq' => 'Alice']])]);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        static::assertTrue($results->pluck('name')->doesntContain('Alice'));
        static::assertGreaterThan(0, $results->count());
    }

    /**
     * Test filtering with $like operator.
     *
     * @return void
     */
    public function testFilteringWithLikeOperator(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => ['$like' => 'Ali']])]);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        static::assertCount(1, $results);
        static::assertSame('Alice', $results->first()->name);
    }

    /**
     * Test filtering with $in operator.
     *
     * @return void
     */
    public function testFilteringWithInOperator(): void
    {
        $this->parseQuery(['filters' => json_encode(['name' => ['$in' => ['Alice', 'Charlie']]])]);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        static::assertCount(2, $results);
        static::assertTrue($results->pluck('name')->contains('Alice'));
        static::assertTrue($results->pluck('name')->contains('Charlie'));
    }

    /**
     * Test filtering with $null operator.
     *
     * @return void
     */
    public function testFilteringWithNullOperator(): void
    {
        $this->parseQuery(['filters' => json_encode(['password' => ['$null' => true]])]);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        foreach ($results as $user) {
            static::assertNull($user->password);
        }
    }

    /**
     * Test filtering with $notNull operator.
     *
     * @return void
     */
    public function testFilteringWithNotNullOperator(): void
    {
        // Set one user's organization_id to a non-null value
        User::where('name', 'Alice')->update(['organization_id' => 1]);

        $this->parseQuery(['filters' => json_encode(['organization_id' => ['$notNull' => true]])]);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        static::assertGreaterThan(0, $results->count());

        foreach ($results as $user) {
            static::assertNotNull($user->organization_id);
        }
    }

    /**
     * Test filtering with relation ($has operator).
     *
     * @return void
     */
    public function testFilteringWithHasRelation(): void
    {
        $this->parseQuery(['filters' => json_encode(['$has' => 'posts'])]);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        // Only Alice and Bob have posts
        static::assertCount(2, $results);
    }

    /**
     * Test filtering with relation ($hasnt operator).
     *
     * @return void
     */
    public function testFilteringWithHasntRelation(): void
    {
        $this->parseQuery(['filters' => json_encode(['$hasnt' => 'posts'])]);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        // Charlie has no posts
        static::assertCount(1, $results);
        static::assertSame('Charlie', $results->first()->name);
    }

    /**
     * Test ordering by column ascending.
     *
     * @return void
     */
    public function testOrderingByColumnAsc(): void
    {
        $this->parseQuery(['order' => 'name:asc']);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        static::assertSame('Alice', $results->first()->name);
        static::assertSame('Charlie', $results->last()->name);
    }

    /**
     * Test ordering by column descending.
     *
     * @return void
     */
    public function testOrderingByColumnDesc(): void
    {
        $this->parseQuery(['order' => 'name:desc']);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        static::assertSame('Charlie', $results->first()->name);
        static::assertSame('Alice', $results->last()->name);
    }

    /**
     * Test ordering by random.
     *
     * @return void
     */
    public function testOrderingByRandom(): void
    {
        $this->parseQuery(['order' => 'random']);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        // Cannot assert order, but we can assert the count is correct
        static::assertCount(3, $results);
    }

    /**
     * Test that limit is applied.
     *
     * @return void
     */
    public function testLimitIsApplied(): void
    {
        $this->parseQuery(['limit' => '2']);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        static::assertCount(2, $results);
    }

    /**
     * Test combined filters, order, and limit.
     *
     * @return void
     */
    public function testCombinedFiltersOrderAndLimit(): void
    {
        $this->parseQuery([
            'filters' => json_encode(['$has' => 'posts']),
            'order'   => 'name:desc',
            'limit'   => '1',
        ]);

        $criteria = $this->app->make(ApiCriteria::class);
        $query    = $criteria->apply(new User);

        $results = $query->get();

        static::assertCount(1, $results);
        static::assertSame('Bob', $results->first()->name);
    }

    /**
     * Parse query parameters through the ApiQuery facade.
     *
     * @param  array  $params
     * @return void
     */
    private function parseQuery(array $params): void
    {
        $request = Request::create('/test', 'GET', $params);

        ApiQuery::parse($request);
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
