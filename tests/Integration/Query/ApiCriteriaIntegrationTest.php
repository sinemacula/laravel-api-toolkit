<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\LimitApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ScopeApplier;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Gathering;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\GatheringResource;
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
#[CoversClass(ScopeApplier::class)]
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
        $this->parseQuery(['filters' => json_encode(['name' => 'Alice'], JSON_THROW_ON_ERROR)]);

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
        $this->parseQuery(['filters' => json_encode(['name' => ['$eq' => 'Bob']], JSON_THROW_ON_ERROR)]);

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
        $this->parseQuery(['filters' => json_encode(['status' => ['$neq' => 'inactive']], JSON_THROW_ON_ERROR)]);

        $results = $this->makeCriteria()->apply(new User)->get();

        self::assertTrue($results->pluck('name')->doesntContain('Charlie'));
        self::assertGreaterThan(0, $results->count());
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
        $this->parseQuery(['filters' => json_encode(['name' => ['$in' => ['Alice', 'Charlie']]], JSON_THROW_ON_ERROR)]);

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

        $this->parseQuery(['filters' => json_encode(['organization_id' => ['$null' => true]], JSON_THROW_ON_ERROR)]);

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

        $this->parseQuery(['filters' => json_encode(['organization_id' => ['$notNull' => true]], JSON_THROW_ON_ERROR)]);

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
        $this->parseQuery(['filters' => json_encode(['$has' => 'posts'], JSON_THROW_ON_ERROR)]);

        $results = $this->makeCriteria()->apply(new User)->get();

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
        $this->parseQuery(['filters' => json_encode(['$hasnt' => 'posts'], JSON_THROW_ON_ERROR)]);

        $results = $this->makeCriteria()->apply(new User)->get();

        self::assertCount(1, $results);

        /** @var \Tests\Fixtures\Models\User $first */
        $first = $results->first();

        self::assertSame('Charlie', $first->name);
    }

    /**
     * Test that a root-level $or cannot escape a caller-applied constraint.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRootOrCannotEscapeACallerAppliedConstraint(): void
    {
        $this->parseQuery([
            'filters' => json_encode([
                '$or' => [['name' => 'Alice'], ['email' => 'charlie@example.com']],
            ], JSON_THROW_ON_ERROR),
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
            'filters' => json_encode(['$has' => 'posts'], JSON_THROW_ON_ERROR),
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
     * Provide filter documents built from logical groups, with the users each
     * reads.
     *
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function logicalGroupProvider(): iterable
    {
        yield 'a key before a group' => ['{"status":"active","$or":[{"name":"Charlie"},{"email":"alice@example.com"}]}', ['Alice']];
        yield 'a key after a group' => ['{"$or":[{"name":"Charlie"},{"email":"alice@example.com"}],"status":"active"}', ['Alice']];
        yield 'an $or of multi-key documents' => ['{"$or":[{"name":"Alice","status":"inactive"},{"name":"Charlie","status":"inactive"}]}', ['Charlie']];
        yield 'an $and of two $or groups' => ['{"$and":[{"$or":[{"name":"Alice"},{"name":"Charlie"}]},{"$or":[{"status":"inactive"},{"email":"bob@example.com"}]}]}', ['Charlie']];
        yield 'an $and inside an $or document' => ['{"$or":[{"name":"Charlie"},{"$and":[{"status":"active"},{"email":"bob@example.com"}]}]}', ['Bob', 'Charlie']];
        yield 'a range inside an $or document' => ['{"$or":[{"id":{"$gt":1,"$lt":3}},{"name":"Alice"}]}', ['Alice', 'Bob']];
        yield '$hasnt inside an $or document' => ['{"$or":[{"name":"Alice"},{"$hasnt":"posts"}]}', ['Alice', 'Charlie']];
        yield 'a relation key inside an $or document' => ['{"$or":[{"posts":{"title":"Bob Post"}},{"name":"Charlie"}]}', ['Bob', 'Charlie']];
    }

    /**
     * Test that a logical group reads exactly the rows it describes.
     *
     * @param  string  $filters
     * @param  array<int, string>  $expected
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('logicalGroupProvider')]
    public function testLogicalGroupReadsTheRowsItDescribes(string $filters, array $expected): void
    {
        Config::set('api-toolkit.resources.resource_map', [Post::class => PostResource::class]);

        $this->parseQuery(['filters' => $filters]);

        $results = $this->makeCriteria()->apply(new User)->orderBy('id')->get();

        self::assertSame($expected, $results->pluck('name')->all());
    }

    /**
     * Provide falsy-value filter documents with the users each request reads.
     *
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function falsyValueFilterProvider(): iterable
    {
        yield 'bare "0"' => ['{"name":"0"}', ['0']];
        yield '$eq "0"' => ['{"name":{"$eq":"0"}}', ['0']];
        yield 'bare 0' => ['{"organization_id":0}', ['0']];
        yield '$eq 0' => ['{"organization_id":{"$eq":0}}', ['0']];
        yield 'bare empty string' => ['{"email":""}', ['']];
        yield '$eq empty string' => ['{"email":{"$eq":""}}', ['']];
        yield 'empty document' => ['{}', ['Alice', 'Bob', 'Charlie', '0', '']];
    }

    /**
     * Test that a falsy filter value reads only the rows holding it.
     *
     * @param  string  $filters
     * @param  array<int, string>  $expected
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('falsyValueFilterProvider')]
    public function testFilteringOnAFalsyValueReadsOnlyTheRowsHoldingIt(string $filters, array $expected): void
    {
        User::create(['name' => '0', 'email' => 'zero@example.com', 'organization_id' => 0]);
        User::create(['name' => '', 'email' => '', 'organization_id' => 1]);

        $this->parseQuery(['filters' => $filters]);

        $results = $this->makeCriteria()->apply(new User)->orderBy('id')->get();

        self::assertSame($expected, $results->pluck('name')->all());
    }

    /**
     * Test that a scope taking nothing and one taking arguments each read the
     * rows they describe.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNamedScopesReadTheRowsTheyDescribe(): void
    {
        $this->seedGatherings();

        self::assertSame(['Launch', 'Retro'], $this->gatheringNames(['$scope' => 'upcoming']));
        self::assertSame(['Launch', 'Workshop'], $this->gatheringNames(['$scope' => ['near' => ['km' => 10, 'lng' => -0.12, 'lat' => 51.5]]]));
        self::assertSame(['Launch'], $this->gatheringNames(['$scope' => ['upcoming' => [], 'near' => ['lat' => 51.5, 'lng' => -0.12, 'km' => 10]]]));
    }

    /**
     * Test that a caller-guarded scope reads the authenticated caller's rows
     * inside $or.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testACallerGuardedScopeReadsTheCallersRowsInsideOr(): void
    {
        $this->seedGatherings();

        $bob = User::query()->where('name', 'Bob')->firstOrFail();

        $this->actingAs($bob);

        self::assertSame(['Launch', 'Retro', 'Workshop'], $this->gatheringNames(['$or' => [['$scope' => 'mine'], ['visibility' => 'public']]]));
    }

    /**
     * Test that a soft-deleted row stays hidden when a scope matches it.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testASoftDeletedRowStaysHiddenWhenAScopeMatchesIt(): void
    {
        $this->seedGatherings();

        Gathering::query()->where('name', 'Retro')->firstOrFail()->delete();

        self::assertSame(['Launch'], $this->gatheringNames(['$scope' => 'upcoming']));
    }

    /**
     * Apply the filters to the gatherings, as the current request, and return
     * the names read, in order.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function gatheringNames(array $filters): array
    {
        assert($this->app !== null);

        $request = Request::create('/gatherings', HttpMethod::GET->getVerb(), ['filters' => json_encode($filters, JSON_THROW_ON_ERROR)]);

        // Rebinding hands the request the authenticated user
        $this->app->instance('request', $request);

        ApiQuery::parse($request);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria $criteria */
        $criteria = $this->app->make(ApiCriteria::class);

        /** @var list<string> */
        return $criteria->usingResource(GatheringResource::class)->apply(new Gathering)->orderBy('name')->pluck('name')->all();
    }

    /**
     * Seed public and private gatherings, past and upcoming, in and out of
     * London, one private one owned by Bob.
     *
     * @return void
     */
    private function seedGatherings(): void
    {
        $bob = User::query()->where('name', 'Bob')->firstOrFail();

        Gathering::create(['name' => 'Launch', 'visibility' => 'public', 'starts_at' => now()->addWeek(), 'latitude' => 51.51, 'longitude' => -0.13]);
        Gathering::create(['name' => 'Retro', 'visibility' => 'private', 'user_id' => $bob->id, 'starts_at' => now()->addDay(), 'latitude' => 48.85, 'longitude' => 2.35]);
        Gathering::create(['name' => 'Workshop', 'visibility' => 'public', 'starts_at' => now()->subWeek(), 'latitude' => 51.49, 'longitude' => -0.1]);
        Gathering::create(['name' => 'Offsite', 'visibility' => 'private', 'starts_at' => now()->subDay(), 'latitude' => 40.71, 'longitude' => -74.0]);
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
