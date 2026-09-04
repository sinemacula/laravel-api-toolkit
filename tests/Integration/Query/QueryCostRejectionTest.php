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
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\QueryCostGuard;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\TestCase;

/**
 * End-to-end tests for the structural query-cost caps.
 *
 * Drives real HTTP requests through the parse and criteria tiers against a real
 * database, asserting both halves of the contract: an over-cost request is
 * answered with the typed rejection envelope, and the query log is empty
 * afterwards, so the request was refused before a single statement was issued.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
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
     * Set up a repository-backed users route and seed the fixtures.
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

        $this->assertRejectionEnvelope($response, 'filters', 'max_depth', 3, 4);
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

        $this->assertRejectionEnvelope($response, 'filters', 'max_bytes', 8192, strlen($filters));
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

        $this->assertRejectionEnvelope($response, 'page', 'max_offset', 25, 26);
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

        $this->assertRejectionEnvelope($response, 'limit', 'max_limit', 25, 26);
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
     * @param  string  $reason
     * @param  int  $limit
     * @param  int  $actual
     * @return void
     */
    private function assertRejectionEnvelope(TestResponse $response, string $parameter, string $reason, int $limit, int $actual): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ErrorCode::QUERY_TOO_EXPENSIVE->getCode());
        $response->assertJsonPath('error.title', 'Query Too Expensive');
        $response->assertJsonPath(
            'error.detail',
            'The query exceeds a limit on how much work a single request may ask for, please narrow it and try again',
        );
        $response->assertJsonPath('error.meta.parameter', $parameter);
        $response->assertJsonPath('error.meta.reason', $reason);
        $response->assertJsonPath('error.meta.limit', $limit);
        $response->assertJsonPath('error.meta.actual', $actual);

        self::assertSame([], $this->queries);
    }
}
