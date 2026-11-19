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
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Concerns\OffsetPaginator;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\QueryCostGuard;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\AggregatingUserResource;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end tests for the structural query-cost caps.
 *
 * An over-cost request must get the typed rejection envelope before any
 * statement is issued.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(OffsetPaginator::class)]
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
     * The two routes resolve different resource types for the same model,
     * telling a cap keyed on the resolved type from one keyed on the client's.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [AggregatingUserResource::class, FilterableUserResource::class, PostResource::class, UserResource::class]);

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
     * Test that a filter nested beyond the depth cap issues no SQL.
     *
     * @return void
     */
    public function testFilterNestedBeyondTheDepthCapIssuesNoSql(): void
    {
        $filters = json_encode(['$or' => [['$and' => [['$or' => [['$and' => [['name' => 'Alice']]]]]]]]]);

        $response = $this->recordQueries('/users?filters=' . urlencode((string) $filters));

        $this->assertRejectionEnvelope($response, 'filters', '/$or/0/$and/0/$or/0/$and', 'max_depth', 3, 4);
    }

    /**
     * Test that a filter nested beyond the parse-depth cap issues no SQL.
     *
     * It also exceeds the dispatch cap, so the reason proves the parse tier
     * refused first.
     *
     * @return void
     */
    public function testFilterNestedBeyondTheParseDepthCapIssuesNoSql(): void
    {
        $filters = ['name' => 'Alice'];

        for ($level = 0; $level < 8; $level++) {
            $filters = ['$or' => [$filters]];
        }

        $response = $this->recordQueries('/users?filters=' . urlencode((string) json_encode($filters)));

        $this->assertRejectionEnvelope($response, 'filters', '', 'max_parse_depth', 16, 17);
    }

    /**
     * Test that a filter visiting more keys than the node cap issues no SQL.
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
     * Test that a filter visiting exactly the node cap is answered.
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
     * Test that a value list longer than the item cap issues no SQL.
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
     * Test that a value list of exactly the item cap is answered.
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
     * Test that a filter larger than the byte cap issues no SQL.
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
     * Test that a page beyond the offset cap is refused, not clamped.
     *
     * @return void
     */
    public function testPageBeyondTheOffsetCapIssuesNoSql(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 250);

        $response = $this->recordQueries('/users?page=27&limit=10');

        // The cap counts skipped rows but the refusal names pages
        $this->assertRejectionEnvelope($response, 'page', '', 'max_offset', 26, 27);
    }

    /**
     * Test that a page size beyond the parser ceiling is refused, not reduced.
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
     * Test that sorting by more columns than the cap allows issues no SQL.
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
     * Test that the sort-key cap is measured before the sortable allowlist.
     *
     * The undeclared key is sent alone first to show the allowlist answers what
     * the cap lets through.
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
     * Test that aggregates beyond the cap issue no SQL.
     *
     * @return void
     */
    public function testAggregatesBeyondTheCapIssueNoSql(): void
    {
        Config::set('api-toolkit.query_cost.max_aggregates', 2);

        $response = $this->recordQueries('/aggregate-users?' . http_build_query([
            'aggregates' => [UserResource::RESOURCE_TYPE => 'posts_count,posts_sum_id,posts_avg_id'],
        ]));

        $this->assertRejectionEnvelope($response, 'aggregates', '', 'max_aggregates', 2, 3);
    }

    /**
     * Test that an aggregate requested twice is charged once against the cap
     * and loaded once.
     *
     * @return void
     */
    public function testRepeatedAggregateIsChargedAndLoadedOnce(): void
    {
        Config::set('api-toolkit.query_cost.max_aggregates', 1);

        $expected = $this->seedAuthorWithPosts();

        $response = $this->recordQueries('/aggregate-users?' . http_build_query([
            'fields'     => [UserResource::RESOURCE_TYPE => 'name,aggregates'],
            'filters'    => json_encode(['name' => 'Carol']),
            'aggregates' => [UserResource::RESOURCE_TYPE => 'posts_avg_id,posts_avg_id'],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.aggregates', ['posts_avg_id' => $expected]);

        $statements = $this->aggregateStatements();

        self::assertCount(1, $statements);
        self::assertSame(1, substr_count($statements[0], 'aggregate_posts_avg_id'));
    }

    /**
     * Test that an aggregate keyed on the resolved resource type is loaded.
     *
     * @return void
     */
    public function testAggregateKeyedOnTheResolvedResourceTypeIsLoaded(): void
    {
        $expected = $this->seedAuthorWithPosts();

        $response = $this->recordQueries('/aggregate-users?' . http_build_query([
            'fields'     => [UserResource::RESOURCE_TYPE => 'name,aggregates'],
            'filters'    => json_encode(['name' => 'Carol']),
            'aggregates' => [UserResource::RESOURCE_TYPE => 'posts_avg_id'],
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.aggregates.posts_avg_id', $expected);

        self::assertCount(1, $this->aggregateStatements());
    }

    /**
     * Test that aggregates keyed on a type the response does not render are not
     * loaded.
     *
     * @return void
     */
    public function testAggregatesKeyedOnAnotherResourceTypeAreNotLoaded(): void
    {
        $this->seedAuthorWithPosts();

        $response = $this->recordQueries('/aggregate-users?' . http_build_query([
            'fields'     => [UserResource::RESOURCE_TYPE => 'name'],
            'filters'    => json_encode(['name' => 'Carol']),
            'aggregates' => [AggregatingUserResource::RESOURCE_TYPE => 'posts_count,posts_avg_id'],
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');

        self::assertSame(['_type', 'id', 'name'], array_keys((array) $response->json('data.0')));
        self::assertSame([], $this->aggregateStatements());
    }

    /**
     * Test that aggregates keyed on a type the response does not render still
     * count toward the cap.
     *
     * @return void
     */
    public function testAggregatesKeyedOnAnotherResourceTypeCountTowardTheCap(): void
    {
        Config::set('api-toolkit.query_cost.max_aggregates', 1);

        $response = $this->recordQueries('/aggregate-users?' . http_build_query([
            'fields'     => [UserResource::RESOURCE_TYPE => 'name'],
            'aggregates' => [AggregatingUserResource::RESOURCE_TYPE => 'posts_count,posts_avg_id'],
        ]));

        $this->assertRejectionEnvelope($response, 'aggregates', '', 'max_aggregates', 1, 2);
    }

    /**
     * Test that the aggregates of an embedded type count toward the cap with
     * the root's.
     *
     * @return void
     */
    public function testEmbeddedAggregatesBeyondTheCapIssueNoSql(): void
    {
        Config::set('api-toolkit.query_cost.max_aggregates', 1);

        $response = $this->recordQueries('/aggregate-users?' . http_build_query([
            'fields' => [
                UserResource::RESOURCE_TYPE => 'name,posts,aggregates',
                PostResource::RESOURCE_TYPE => 'title,aggregates',
            ],
            'aggregates' => [
                UserResource::RESOURCE_TYPE => 'posts_count',
                PostResource::RESOURCE_TYPE => 'tags_count',
            ],
        ]));

        $this->assertRejectionEnvelope($response, 'aggregates', '', 'max_aggregates', 1, 2);
    }

    /**
     * Test that a single embedded aggregate within the cap is answered.
     *
     * @return void
     */
    public function testSingleEmbeddedAggregateWithinTheCapIsAnswered(): void
    {
        Config::set('api-toolkit.query_cost.max_aggregates', 1);

        $this->seedAuthorWithPosts();

        $response = $this->recordQueries('/aggregate-users?' . http_build_query([
            'fields' => [
                UserResource::RESOURCE_TYPE => 'name,posts',
                PostResource::RESOURCE_TYPE => 'title,aggregates',
            ],
            'filters'    => json_encode(['name' => 'Carol']),
            'aggregates' => [PostResource::RESOURCE_TYPE => 'tags_count'],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.posts.0.aggregates.tags_count', 0);
        $response->assertJsonPath('data.0.posts.1.aggregates.tags_count', 0);
    }

    /**
     * Test that a request within every cap is answered.
     *
     * @return void
     */
    public function testRequestWithinEveryCapIsAnswered(): void
    {
        $filters = json_encode(['$or' => [['$and' => [['name' => 'Alice']]]]]);

        $response = $this->recordQueries('/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');

        self::assertNotEmpty($this->queries);
    }

    /**
     * Seed an author with two posts, returning the average of their keys.
     *
     * @return float
     */
    private function seedAuthorWithPosts(): float
    {
        $author = User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'status' => 'active']);

        $first  = Post::create(['user_id' => $author->id, 'title' => 'First', 'body' => 'A post']);
        $second = Post::create(['user_id' => $author->id, 'title' => 'Second', 'body' => 'Another post']);

        return ($first->id + $second->id) / 2;
    }

    /**
     * Return the recorded statements carrying the posts-average subquery.
     *
     * @return array<int, string>
     */
    private function aggregateStatements(): array
    {
        $statements = [];

        foreach ($this->queries as $query) {

            $sql = $query['query'] ?? null;

            if (!is_string($sql) || !str_contains($sql, 'aggregate_posts_avg_id')) {
                continue;
            }

            $statements[] = $sql;
        }

        return $statements;
    }

    /**
     * Dispatch the request, recording every statement it issued.
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
     * Assert the typed rejection envelope and that no statement was issued.
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
