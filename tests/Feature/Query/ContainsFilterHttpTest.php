<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\ContainsOperator;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Log;
use Tests\Fixtures\Repositories\LogRepository;
use Tests\Fixtures\Resources\LogResource;
use Tests\TestCase;

/**
 * Feature tests exercising JSON containment filtering over real HTTP requests.
 *
 * Drives the containment operator against a filterable JSON column: an array
 * payload resolves via a JSON-contains constraint, while a comma-separated
 * string resolves to an OR-combined containment group returning the union of
 * matches rather than their intersection. SQLite's grammar rejects the
 * underlying JSON-containment clause, so each scenario is skipped on that
 * driver and only runs under MySQL or PostgreSQL.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(ContainsOperator::class)]
final class ContainsFilterHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up a repository-backed logs route and seed rows with JSON contexts.
     *
     * Each row carries a top-level JSON array in its context column so a
     * containment constraint can match against the array members directly.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/logs', function (LogRepository $repository): ApiResourceCollection {

            $logs = $repository->usingResource(LogResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($logs, LogResource::class);
        });

        Log::create(['level' => 'info', 'message' => 'first', 'context' => ['php', 'laravel']]);
        Log::create(['level' => 'info', 'message' => 'second', 'context' => ['rust']]);
        Log::create(['level' => 'info', 'message' => 'third', 'context' => ['go']]);
    }

    /**
     * Test that an array payload resolves via a JSON containment constraint.
     *
     * @return void
     */
    public function testArrayPayloadResolvesViaJsonContainment(): void
    {
        $this->skipOnSqlite();

        $response = $this->query(['context' => ['$contains' => ['php']]]);

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.message', 'first');
    }

    /**
     * Test that a comma-separated string resolves to the union of containment
     * matches rather than their intersection.
     *
     * @return void
     */
    public function testCommaSeparatedStringResolvesToTheUnion(): void
    {
        $this->skipOnSqlite();

        $response = $this->query(['context' => ['$contains' => 'php,rust']]);

        $messages = array_column((array) $response->json('data'), 'message');

        $response->assertJsonPath('meta.total', 2);
        self::assertEqualsCanonicalizing(['first', 'second'], $messages);
    }

    /**
     * Test that a scalar payload resolves via a JSON containment constraint.
     *
     * @return void
     */
    public function testScalarPayloadResolvesViaJsonContainment(): void
    {
        $this->skipOnSqlite();

        $response = $this->query(['context' => ['$contains' => 'go']]);

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.message', 'third');
    }

    /**
     * Issue a filtered request against the logs route and return the response.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function query(array $filters): TestResponse
    {
        $response = $this->getJson('/logs?filters=' . urlencode((string) json_encode($filters)));

        $response->assertOk();

        return $response;
    }

    /**
     * Skip the current test on the SQLite driver, whose grammar rejects the
     * JSON-containment clause the containment operator emits.
     *
     * @return void
     */
    private function skipOnSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        self::markTestSkipped('JSON containment is unsupported on the SQLite driver.');
    }
}
