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
 * Feature tests for JSON containment filtering over real HTTP requests.
 *
 * SQLite's grammar rejects the JSON-containment clause, so these run only on
 * MySQL and PostgreSQL.
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
     * Set up a logs route and seed rows whose context is a top-level JSON
     * array.
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

        $response = $this->queryFilters(['context' => ['$contains' => ['php']]]);

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.message', 'first');
    }

    /**
     * Test that a comma-separated string resolves to the union of containment
     * matches.
     *
     * @return void
     */
    public function testCommaSeparatedStringResolvesToTheUnion(): void
    {
        $this->skipOnSqlite();

        $response = $this->queryFilters(['context' => ['$contains' => 'php,rust']]);

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

        $response = $this->queryFilters(['context' => ['$contains' => 'go']]);

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.message', 'third');
    }

    /**
     * Issue a filtered request against the logs route and return the response.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function queryFilters(array $filters): TestResponse
    {
        $response = $this->getJson('/logs?filters=' . urlencode((string) json_encode($filters)));

        $response->assertOk();

        return $response;
    }

    /**
     * Skip the current test on SQLite, whose grammar rejects the
     * JSON-containment clause.
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
