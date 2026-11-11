<?php

declare(strict_types = 1);

namespace Tests\Integration\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * End-to-end tests for the per-operator capability gate.
 *
 * A refused operator must be rejected before any statement reaches the
 * database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Capability::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(QuerySurface::class)]
final class CapabilityRefusalTest extends TestCase
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

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);
    }

    /**
     * Test that a negation on an equality-only column issues no SQL.
     *
     * @return void
     */
    public function testNegationOnAnEqualityColumnIssuesNoSql(): void
    {
        $response = $this->recordQueries(['name' => ['$neq' => 'Alice']]);

        $this->assertRefusalEnvelope($response, 'name', '$neq', '$eq, $in, $null, $notNull');
    }

    /**
     * Test that a comparison on an equality-only column issues no SQL.
     *
     * @return void
     */
    public function testComparisonOnAnEqualityColumnIssuesNoSql(): void
    {
        $response = $this->recordQueries(['email' => ['$gt' => 'a@example.com']]);

        $this->assertRefusalEnvelope($response, 'email', '$gt', '$eq, $in, $null, $notNull');
    }

    /**
     * Test that containment on an ordered-range column issues no SQL.
     *
     * @return void
     */
    public function testContainmentOnARangeColumnIssuesNoSql(): void
    {
        $response = $this->recordQueries(['id' => ['$contains' => '1']]);

        $this->assertRefusalEnvelope($response, 'id', '$contains', '$eq, $in, $gt, $ge, $lt, $le, $between, $null, $notNull');
    }

    /**
     * Test that a permitted operator is applied against the database.
     *
     * @return void
     */
    public function testPermittedOperatorIsAnswered(): void
    {
        $response = $this->recordQueries(['name' => ['$in' => ['Alice']]]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Alice');

        self::assertNotEmpty($this->queries);
    }

    /**
     * Test that a token the capability matrix does not govern is applied.
     *
     * @return void
     */
    public function testTokenTheMatrixDoesNotGovernIsAnswered(): void
    {
        app(OperatorRegistry::class)->override('$starts', static function (Builder $query, string $column, mixed $value): void {
            $query->where($column, 'like', (is_scalar($value) ? (string) $value : '') . '%');
        });

        $response = $this->recordQueries(['name' => ['$starts' => 'Ali']]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Alice');
    }

    /**
     * Dispatch a filtered request, recording every statement it issued.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function recordQueries(array $filters): TestResponse
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/users?filters=' . urlencode((string) json_encode($filters)));

        $this->queries = DB::getQueryLog();

        DB::disableQueryLog();

        return $response;
    }

    /**
     * Assert the refusal envelope and that the request issued no statement.
     *
     * @param  \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>  $response
     * @param  string  $column
     * @param  string  $operator
     * @param  string  $accepts
     * @return void
     */
    private function assertRefusalEnvelope(TestResponse $response, string $column, string $operator, string $accepts): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ErrorCode::INVALID_INPUT->getCode());

        $meta = (array) $response->json('error.meta');

        self::assertSame(
            ['The "' . $operator . '" operator is not permitted on the "' . $column . '" key for this resource, which accepts ' . $accepts . '.'],
            $meta['filters.' . $column . '.' . $operator] ?? [],
        );

        self::assertSame([], $this->queries);
    }
}
