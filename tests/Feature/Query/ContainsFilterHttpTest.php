<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterValueGuard;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\ContainsOperator;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Log;
use Tests\Fixtures\Repositories\LogRepository;
use Tests\Fixtures\Resources\LogResource;
use Tests\TestCase;

/**
 * Feature tests for JSON containment filtering over real HTTP requests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(FilterValueGuard::class)]
#[CoversClass(ContainsOperator::class)]
final class ContainsFilterHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var string The position every condition is refused at */
    private const string POSITION = 'filters.context.$contains';

    /**
     * Set up a logs route and seed rows whose context covers each JSON shape.
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

        Log::create(['level' => 'info', 'message' => 'pair', 'context' => ['php', 'laravel']]);
        Log::create(['level' => 'info', 'message' => 'php', 'context' => ['php']]);
        Log::create(['level' => 'info', 'message' => 'rust', 'context' => ['rust']]);
        Log::create(['level' => 'info', 'message' => 'numbers', 'context' => [1, 2]]);
        Log::create(['level' => 'info', 'message' => 'precise', 'context' => [1.2345678901234567]]);
        Log::create(['level' => 'info', 'message' => 'flag', 'context' => [true]]);
        Log::create(['level' => 'info', 'message' => 'object', 'context' => ['role' => 'admin']]);
        Log::create(['level' => 'info', 'message' => 'scalar', 'context' => 'rust']);
    }

    /**
     * Provide payloads with the messages of the rows each matches.
     *
     * @return iterable<string, array{mixed, array<int, string>}>
     */
    public static function matchingPayloadProvider(): iterable
    {
        yield 'single-item list' => [['php'], ['pair', 'php']];
        yield 'list held whole by one row' => [['php', 'laravel'], ['pair']];
        yield 'list held whole by no row' => [['php', 'rust'], []];
        yield 'string' => ['rust', ['rust', 'scalar']];
        yield 'value of an object member' => ['admin', []];
        yield 'comma-separated string' => ['laravel,rust', ['pair', 'rust', 'scalar']];
        yield 'JSON-encoded list' => ['["php"]', []];
        yield 'JSON-encoded object' => ['{"role":"admin"}', []];
        yield 'integer' => [1, ['numbers']];
        yield 'integer list' => [[1, 2], ['numbers']];
        yield 'float equal to an integer' => [2.0, ['numbers']];
        yield 'float of full precision' => [1.2345678901234567, ['precise']];
        yield 'digit string' => ['1', []];
        yield 'numeric comma-separated string' => ['1,2', []];
        yield 'true' => [true, ['flag']];
        yield 'false' => [false, []];
    }

    /**
     * Test that a payload matches the same rows on every engine.
     *
     * @param  mixed  $payload
     * @param  array<int, string>  $messages
     * @return void
     */
    #[DataProvider('matchingPayloadProvider')]
    #[Group('mysql')]
    #[Group('pgsql')]
    public function testPayloadMatchesTheSameRowsOnEveryEngine(mixed $payload, array $messages): void
    {
        $response = $this->queryContains($payload);

        $response->assertOk();

        self::assertEqualsCanonicalizing($messages, array_column((array) $response->json('data'), 'message'));
    }

    /**
     * Provide payloads outside the operator's shape with the message each is
     * refused with.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function refusedPayloadProvider(): iterable
    {
        $elements = 'The $contains condition on "context" must be a single value or a non-empty list of values.';

        yield 'object' => [['role' => 'admin'], $elements];
        yield 'empty list' => [[], $elements];
        yield 'null' => [null, 'The $contains condition on "context" cannot be null; $null matches a missing value and $notNull a present one.'];
        yield 'nested list' => [[['php']], 'Item 0 of the $contains condition on "context" must be a single value other than null.'];
        yield 'NUL character' => ["php\0", 'The $contains condition on "context" must be free of NUL characters.'];
        yield 'only delimiters' => [',,', 'The filters.context.$contains filter adds no condition, so it would match every row.'];
    }

    /**
     * Test that a payload outside the operator's shape is refused at the
     * condition on every engine.
     *
     * @param  mixed  $payload
     * @param  string  $message
     * @return void
     */
    #[DataProvider('refusedPayloadProvider')]
    #[Group('mysql')]
    #[Group('pgsql')]
    public function testPayloadOutsideTheShapeIsRefusedAtTheCondition(mixed $payload, string $message): void
    {
        $response = $this->queryContains($payload);

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta', [self::POSITION => [$message]]);
    }

    /**
     * Issue a request filtering the logs' context by the payload.
     *
     * @param  mixed  $payload
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function queryContains(mixed $payload): TestResponse
    {
        $filters = (string) json_encode(['context' => ['$contains' => $payload]], JSON_PRESERVE_ZERO_FRACTION);

        return $this->getJson('/logs?filters=' . urlencode($filters));
    }
}
