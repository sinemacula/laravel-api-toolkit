<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\QueryCostGuard;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end tests for the structural query-cost caps.
 *
 * Drives real HTTP requests through the parse and criteria tiers against a real
 * database, asserting both halves of the contract: an over-cost request is
 * answered with the typed rejection envelope, and the query log is empty
 * afterwards, so the request was refused before a single statement was issued.
 *
 * The envelope is pinned key by key, the JSON pointer among them, so a client
 * refused inside a nested group is told where within its own document the cap
 * was exhausted rather than only that one was.
 *
 * The flat caps are pinned against the resource type the criteria resolve for
 * the route rather than one a test hands the guard: the sort-key cap is
 * measured before the sortable allowlist walks the keys, and the aggregate cap
 * counts the same relation-keyed maps the eager loader later reads, so a map
 * the cap does not count is one the query does not issue either.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(QueryCostGuard::class)]
#[CoversClass(QueryParameterValidator::class)]
#[CoversClass(QueryTooExpensiveException::class)]
final class QueryCostRejectionTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var array<int, array<string, mixed>> The statements the recorded request issued */
    private array $queries = [];

    /**
     * Set up the repository-backed users routes and seed the fixtures.
     *
     * The two routes resolve a different resource, and so a different resource
     * type, for the same model, so a test can tell a cap keyed on the type the
     * criteria resolve from one keyed on whatever type the client sent.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.resources.resource_map', [
            Post::class => PostResource::class,
        ]);

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/aggregate-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(UserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, UserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);
    }

    /**
     * Test that a filter document nested beyond the depth cap is refused before
     * any statement reaches the database.
     *
     * @return void
     */
    public function testFilterNestedBeyondTheDepthCapIssuesNoSql(): void
    {
        $filters = json_encode(['$or' => ['$and' => ['$or' => ['$and' => ['name' => 'Alice']]]]]);

        $response = $this->recordQueries('/users?filters=' . urlencode((string) $filters));

        $this->assertRejectionEnvelope($response, 'filters', '/$or/$and/$or/$and', 'max_depth', 3, 4);
    }

    /**
     * Test that a filter document nested beyond the parse-depth cap is refused
     * by the parse tier before any statement reaches the database.
     *
     * The document nests beyond the dispatch depth cap as well, so the reason
     * the envelope carries names which of the two tiers refused it, and proves
     * the parse tier measures nesting before the document is dispatched.
     *
     * @return void
     */
    public function testFilterNestedBeyondTheParseDepthCapIssuesNoSql(): void
    {
        $filters = ['name' => 'Alice'];

        for ($level = 0; $level < 16; $level++) {
            $filters = ['$or' => $filters];
        }

        $response = $this->recordQueries('/users?filters=' . urlencode((string) json_encode($filters)));

        $this->assertRejectionEnvelope($response, 'filters', '', 'max_parse_depth', 16, 17);
    }

    /**
     * Test that a filter document visiting more keys than the node cap is
     * refused before any statement reaches the database, reported at the key
     * that exhausted the budget.
     *
     * Every entry names a relation the surface declares, and each one adds a
     * correlated subquery of its own, so the document is refused on the total
     * it asks for rather than on any single key being unacceptable.
     *
     * @return void
     */
    public function testFilterVisitingMoreKeysThanTheNodeCapIssuesNoSql(): void
    {
        $filters = json_encode(['$has' => array_fill(0, 100, 'posts')]);

        $response = $this->recordQueries('/users?filters=' . urlencode((string) $filters));

        $this->assertRejectionEnvelope($response, 'filters', '/$has/99', 'max_nodes', 100, 101);
    }

    /**
     * Test that a filter document visiting exactly the node cap is answered
     * from the database, so the rejection above is the budget running out
     * rather than the shape of the document being refused outright.
     *
     * @return void
     */
    public function testFilterVisitingExactlyTheNodeCapIsAnswered(): void
    {
        $author = User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'status' => 'active']);

        Post::create(['user_id' => $author->id, 'title' => 'First', 'body' => 'A post']);

        $filters = json_encode(['$has' => array_fill(0, 99, 'posts')]);

        $response = $this->recordQueries('/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Carol');

        self::assertNotEmpty($this->queries);
    }

    /**
     * Test that an operator value list longer than the item cap is refused
     * before any statement reaches the database, reported at the operator that
     * carried it.
     *
     * @return void
     */
    public function testOperatorValueListBeyondTheItemCapIssuesNoSql(): void
    {
        $filters = json_encode(['id' => ['$in' => range(1, 501)]]);

        $response = $this->recordQueries('/users?filters=' . urlencode((string) $filters));

        $this->assertRejectionEnvelope($response, 'filters', '/id/$in', 'max_in_items', 500, 501);
    }

    /**
     * Test that an operator value list of exactly the item cap is answered from
     * the database, so the rejection above turns on the item count rather than
     * on the list itself.
     *
     * @return void
     */
    public function testOperatorValueListAtExactlyTheItemCapIsAnswered(): void
    {
        $filters = json_encode(['id' => ['$in' => range(1, 500)]]);

        $response = $this->recordQueries('/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        self::assertNotEmpty($this->queries);
    }

    /**
     * Test that a filter document larger than the byte cap is refused by the
     * parse tier before any statement reaches the database.
     *
     * @return void
     */
    public function testFilterLargerThanTheByteCapIssuesNoSql(): void
    {
        $filters = '{"name":"' . str_repeat('a', 8200) . '"}';

        $response = $this->recordQueries('/users?filters=' . urlencode($filters));

        $this->assertRejectionEnvelope($response, 'filters', '', 'max_bytes', 8192, strlen($filters));
    }

    /**
     * Test that a page beyond the offset cap is refused before any statement
     * reaches the database, rather than being clamped to the last page within
     * the cap.
     *
     * @return void
     */
    public function testPageBeyondTheOffsetCapIssuesNoSql(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 25);

        $response = $this->recordQueries('/users?page=26');

        $this->assertRejectionEnvelope($response, 'page', '', 'max_offset', 25, 26);
    }

    /**
     * Test that a page size beyond the parser ceiling is refused before any
     * statement reaches the database, rather than being reduced to the ceiling
     * and answered with a page the client cannot tell from the last one.
     *
     * @return void
     */
    public function testPageSizeBeyondTheParserCeilingIssuesNoSql(): void
    {
        Config::set('api-toolkit.parser.max_limit', 25);

        $response = $this->recordQueries('/users?limit=26');

        $this->assertRejectionEnvelope($response, 'limit', '', 'max_limit', 25, 26);
    }

    /**
     * Test that a request sorting by more columns than the cap allows is
     * refused before any statement reaches the database, even though every
     * column it names is declared sortable.
     *
     * @return void
     */
    public function testSortKeysBeyondTheCapIssueNoSql(): void
    {
        Config::set('api-toolkit.query_cost.max_order_keys', 2);

        $response = $this->recordQueries('/users?order=name:asc,id:desc,created_at:asc');

        $this->assertRejectionEnvelope($response, 'order', '', 'max_order_keys', 2, 3);
    }

    /**
     * Test that the sort-key cap is measured before the sortable allowlist, so
     * an over-cap request is refused on its cost rather than on the first key
     * the allowlist happens to object to.
     *
     * The undeclared key is sent alone first, to establish that the allowlist
     * is what answers a request the cap lets through, and so that the cost
     * envelope below cannot be mistaken for the allowlist rejecting the same
     * key by another name.
     *
     * @return void
     */
    public function testSortKeyCapIsMeasuredBeforeTheSortableAllowlist(): void
    {
        $undeclared = $this->getJson('/users?order=status:desc');

        $undeclared->assertStatus(422);
        $undeclared->assertJsonPath('error.code', ErrorCode::INVALID_INPUT->getCode());

        self::assertArrayHasKey('order.status', (array) $undeclared->json('error.meta'));

        $response = $this->recordQueries('/users?order=name:asc,id:desc,created_at:asc,status:desc');

        $this->assertRejectionEnvelope($response, 'order', '', 'max_order_keys', 3, 4);
    }

    /**
     * Test that relation aggregates beyond the shipped cap, keyed on the
     * resource type the criteria resolve for the route, are refused before any
     * statement reaches the database.
     *
     * @return void
     */
    public function testAggregatesBeyondTheCapIssueNoSql(): void
    {
        $response = $this->recordQueries('/users?' . http_build_query([
            'counts'   => [FilterableUserResource::RESOURCE_TYPE => 'posts,organization,profile'],
            'sums'     => [FilterableUserResource::RESOURCE_TYPE => ['posts' => 'id,created_at']],
            'averages' => [FilterableUserResource::RESOURCE_TYPE => ['posts' => 'id']],
        ]));

        $this->assertRejectionEnvelope($response, 'aggregates', '', 'max_aggregates', 5, 6);
    }

    /**
     * Test that an aggregate keyed on the resource type the criteria resolve is
     * loaded, so the key the cap counts is the key the query is built from.
     *
     * @return void
     */
    public function testAggregateKeyedOnTheResolvedResourceTypeIsLoaded(): void
    {
        $expected = $this->seedAuthorWithPosts();

        $response = $this->recordQueries('/aggregate-users?' . http_build_query([
            'fields'   => [UserResource::RESOURCE_TYPE => 'name,averages'],
            'filters'  => json_encode(['name' => 'Carol']),
            'averages' => [UserResource::RESOURCE_TYPE => ['posts' => 'id']],
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.averages.posts_id', $expected);

        self::assertNotEmpty($this->aggregateStatements());
    }

    /**
     * Test that aggregates keyed on another resource type are neither counted
     * against the cap nor loaded, so the type mismatch that leaves the cap
     * nothing to measure leaves the query nothing to add either.
     *
     * The request carries more aggregates than the cap allows, and is answered
     * rather than refused, because the route resolves a resource type the
     * client did not key them under.
     *
     * @return void
     */
    public function testAggregatesKeyedOnAnotherResourceTypeAreNeitherCountedNorLoaded(): void
    {
        $this->seedAuthorWithPosts();

        $response = $this->recordQueries('/aggregate-users?' . http_build_query([
            'fields'   => [UserResource::RESOURCE_TYPE => 'name,averages'],
            'filters'  => json_encode(['name' => 'Carol']),
            'counts'   => [FilterableUserResource::RESOURCE_TYPE => 'posts,organization,profile'],
            'sums'     => [FilterableUserResource::RESOURCE_TYPE => ['posts' => 'id,created_at']],
            'averages' => [FilterableUserResource::RESOURCE_TYPE => ['posts' => 'id']],
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');

        self::assertSame([], (array) $response->json('data.0.averages'));
        self::assertSame([], $this->aggregateStatements());
    }

    /**
     * Test that a request within every cap is answered from the database, so
     * the empty query log asserted above reflects the rejection rather than an
     * inert query log.
     *
     * @return void
     */
    public function testRequestWithinEveryCapIsAnswered(): void
    {
        $filters = json_encode(['$or' => ['$and' => ['name' => 'Alice']]]);

        $response = $this->recordQueries('/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');

        self::assertNotEmpty($this->queries);
    }

    /**
     * Dispatch the given request with the query log running, retaining every
     * statement it issued.
     *
     * @param  string  $uri
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function recordQueries(string $uri): TestResponse
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson($uri);

        $this->queries = DB::getQueryLog();

        DB::disableQueryLog();

        return $response;
    }

    /**
     * Assert the typed rejection envelope, and that the recorded request issued
     * no statement at all.
     *
     * @param  \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>  $response
     * @param  string  $parameter
     * @param  string  $pointer
     * @param  string  $reason
     * @param  int  $limit
     * @param  int  $actual
     * @return void
     */
    private function assertRejectionEnvelope(TestResponse $response, string $parameter, string $pointer, string $reason, int $limit, int $actual): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ErrorCode::QUERY_TOO_EXPENSIVE->getCode());
        $response->assertJsonPath('error.title', 'Query Too Expensive');
        $response->assertJsonPath(
            'error.detail',
            'The query exceeds a limit on how much work a single request may ask for, please narrow it and try again',
        );
        $response->assertJsonPath('error.meta.parameter', $parameter);
        $response->assertJsonPath('error.meta.pointer', $pointer);
        $response->assertJsonPath('error.meta.reason', $reason);
        $response->assertJsonPath('error.meta.limit', $limit);
        $response->assertJsonPath('error.meta.actual', $actual);

        self::assertSame([], $this->queries);
    }
}
