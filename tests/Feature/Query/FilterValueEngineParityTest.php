<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\OpenApi\Builder\FilterSchemaBuilder;
use SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterValueGuard;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Models\Measurement;
use Tests\Fixtures\Repositories\MeasurementRepository;
use Tests\Fixtures\Resources\MeasurementResource;
use Tests\TestCase;

/**
 * Feature tests for filter values against each engine's column types.
 *
 * Every engine gives a value the same answer: the rows it matches, or a 422 at
 * its position, never a database error or a coercion of its own.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(FilterApplier::class)]
#[CoversClass(FilterValueGuard::class)]
#[CoversClass(ColumnLiteral::class)]
#[CoversClass(FilterSchemaBuilder::class)]
final class FilterValueEngineParityTest extends TestCase
{
    use RegistersApiExceptionHandler, ValidatesOpenApiDocuments;

    /** @var string */
    private const string FIRST_TOKEN = '123e4567-e89b-12d3-a456-426614174000';

    /** @var string */
    private const string SECOND_TOKEN = '0b2c7c4e-5d6f-4a1b-9c3d-2e4f6a8b0c1d';

    /**
     * Register the measurements route and seed one row at each extreme.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/measurements', static function (MeasurementRepository $repository): ApiResourceCollection {

            $measurements = $repository->usingResource(MeasurementResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($measurements, MeasurementResource::class);
        });

        Measurement::query()->insert([
            [
                'count'      => 10,
                'total'      => PHP_INT_MAX,
                'ratio'      => PHP_FLOAT_MAX,
                'active'     => true,
                'day'        => '2024-01-01',
                'token'      => self::FIRST_TOKEN,
                'label'      => 'alpha',
                'created_at' => '2024-01-01 10:00:00',
                'updated_at' => '2024-01-01 10:00:00',
            ],
            [
                'count'      => -5,
                'total'      => 0,
                'ratio'      => 0.5,
                'active'     => false,
                'day'        => '2024-06-15',
                'token'      => self::SECOND_TOKEN,
                'label'      => 'beta',
                'created_at' => '2024-06-15 12:30:00',
                'updated_at' => '2024-06-15 12:30:00',
            ],
        ]);
    }

    /**
     * Provide valid values of each kind, with the ids of the rows each matches.
     *
     * @return iterable<string, array{string, array<int, int>}>
     */
    public static function matchingValueProvider(): iterable
    {
        yield 'int4 lower bound' => ['{"count":{"$ge":0}}', [1]];
        yield 'int8 extreme as a number' => ['{"total":{"$eq":9223372036854775807}}', [1]];
        yield 'int8 extreme as a digit string' => ['{"total":{"$eq":"9223372036854775807"}}', [1]];
        yield 'float8 extreme' => ['{"ratio":{"$ge":1.7976931348623157e308}}', [1]];
        yield 'bool true' => ['{"active":true}', [1]];
        yield 'bool 1' => ['{"active":1}', [1]];
        yield 'bool "0"' => ['{"active":"0"}', [2]];
        yield 'bool false' => ['{"active":false}', [2]];
        yield 'date lower bound' => ['{"day":{"$ge":"2024-03-01"}}', [2]];
        yield 'uuid' => ['{"token":"' . self::FIRST_TOKEN . '"}', [1]];
        yield 'empty list' => ['{"count":{"$in":[]}}', []];
        yield 'date-time under another offset' => ['{"created_at":{"$eq":"2024-01-01T12:00:00+02:00"}}', [1]];
        yield 'date-time in UTC' => ['{"created_at":{"$eq":"2024-06-15T12:30:00Z"}}', [2]];
        yield 'date alone as a lower bound' => ['{"created_at":{"$ge":"2024-06-15"}}', [2]];
        yield 'dates alone as bounds' => ['{"created_at":{"$between":["2024-01-01","2024-01-02"]}}', [1]];
    }

    /**
     * Test that a valid value of each kind matches the same rows on every
     * engine.
     *
     * @param  string  $filters
     * @param  array<int, int>  $ids
     * @return void
     */
    #[DataProvider('matchingValueProvider')]
    public function testValidValueMatchesTheSameRowsOnEveryEngine(string $filters, array $ids): void
    {
        self::assertSame($ids, $this->matchedIds($filters));
    }

    /**
     * Test that the date-time each field emits for a row, sent back, matches
     * that row.
     *
     * @return void
     */
    public function testEmittedDateTimeSentBackMatchesItsRow(): void
    {
        $row = $this->getJson('/measurements?filters=' . urlencode('{"id":1}'))->assertOk()->json('data.0');

        self::assertIsArray($row);
        self::assertSame('2024-01-01T10:00:00+00:00', $row['created_at']);
        self::assertSame('2024-01-01T10:00:00.000000Z', $row['updated_at']);

        self::assertSame([1], $this->matchedIds((string) json_encode(['created_at' => ['$eq' => $row['created_at']]])));
        self::assertSame([1], $this->matchedIds((string) json_encode(['updated_at' => $row['updated_at']])));
    }

    /**
     * Provide values the engines would reject, coerce or misread, with the
     * position each is refused at and how its message starts, since an engine's
     * catalogue can name a narrower type.
     *
     * @return iterable<string, array{string, string, non-empty-string}>
     */
    public static function refusedValueProvider(): iterable
    {
        $scalar = 'must be a single value other than null.';
        $null   = 'cannot be null; $null matches a missing value and $notNull a present one.';

        yield 'word on an integer' => ['{"id":"abc"}', 'filters.id', 'The $eq condition on "id" must be an integer'];
        yield 'word bound on an integer' => ['{"id":{"$gt":"abc"}}', 'filters.id.$gt', 'The $gt condition on "id" must be an integer'];
        yield 'integer past int64' => ['{"id":12345678901234567890}', 'filters.id', 'The $eq condition on "id" must be an integer'];
        yield 'nested lists' => ['{"count":{"$in":[[1,2],[3]]}}', 'filters.count.$in', 'Item 0 of the $in condition on "count" ' . $scalar];
        yield 'nested bound' => ['{"id":{"$between":[[1,2],3]}}', 'filters.id.$between', 'Item 0 of the $between condition on "id" ' . $scalar];
        yield 'list on a comparison' => ['{"label":{"$eq":["alpha","beta"]}}', 'filters.label.$eq', 'The $eq condition on "label" must be a single value.'];
        yield 'null comparison' => ['{"count":{"$gt":null}}', 'filters.count.$gt', 'The $gt condition on "count" ' . $null];
        yield 'null shorthand' => ['{"label":null}', 'filters.label', 'The $eq condition on "label" ' . $null];
        yield 'word on a date-time' => ['{"created_at":{"$ge":"banana"}}', 'filters.created_at.$ge', 'The $ge condition on "created_at" must be a date-time'];
        yield 'past the month end' => ['{"day":"2024-02-30"}', 'filters.day', 'The $eq condition on "day" must be a date as YYYY-MM-DD.'];
        yield 'overflowing number' => ['{"ratio":{"$eq":1e400}}', 'filters.ratio.$eq', 'The $eq condition on "ratio" must be a finite number.'];
        yield 'word on a boolean' => ['{"active":"true"}', 'filters.active', 'The $eq condition on "active" must be '];
        yield 'NUL in a string' => ['{"label":"a\u0000b"}', 'filters.label', 'The $eq condition on "label" must be free of NUL characters.'];
        yield 'bool on a string' => ['{"label":true}', 'filters.label', 'The $eq condition on "label" must be a string or a number.'];
    }

    /**
     * Test that a value an engine would reject, coerce or misread is refused
     * with a 422 on every engine.
     *
     * @param  string  $filters
     * @param  string  $position
     * @param  non-empty-string  $prefix
     * @return void
     */
    #[DataProvider('refusedValueProvider')]
    public function testValueAnEngineWouldMisreadIsRefusedOnEveryEngine(string $filters, string $position, string $prefix): void
    {
        $response = $this->filter($filters);

        $response->assertStatus(422);

        $errors = $response->json('error.meta');

        self::assertIsArray($errors);
        self::assertSame([$position], array_keys($errors));
        self::assertIsString($errors[$position][0] ?? null);
        self::assertStringStartsWith($prefix, $errors[$position][0]);
    }

    /**
     * Provide values only an engine with a narrower column type would reject,
     * with the message each is refused with there.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function narrowlyTypedValueProvider(): iterable
    {
        yield 'integer outside int4' => ['{"count":3000000000}', 'filters.count', 'The $eq condition on "count" must be an integer from -2147483648 to 2147483647.'];
        yield 'word on a uuid' => ['{"token":"not-a-uuid"}', 'filters.token', 'The $eq condition on "token" must be a UUID.'];
    }

    /**
     * Test that a value a narrowly typed column would reject is refused where
     * the catalogue reports that type, and otherwise matches nothing.
     *
     * @param  string  $filters
     * @param  string  $position
     * @param  string  $message
     * @return void
     */
    #[DataProvider('narrowlyTypedValueProvider')]
    public function testValueANarrowlyTypedColumnWouldRejectNeverReachesTheEngine(string $filters, string $position, string $message): void
    {
        $response = $this->filter($filters);

        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::assertSame([], $this->ids($response->assertOk()));
            return;
        }

        $response->assertStatus(422);
        $response->assertJsonPath('error.meta', [$position => [$message]]);
    }

    /**
     * Test that the canonical value of the type each column exports, which the
     * exported filter document accepts, is answered on every engine.
     *
     * @return void
     */
    public function testTheCanonicalValueOfEachExportedColumnTypeIsAnswered(): void
    {
        assert($this->app !== null);

        Config::set('api-toolkit.resources.resource_map', [Measurement::class => MeasurementResource::class]);

        $claimed  = [];
        $document = ['components' => ['schemas' => $this->app->make(FilterSchemaBuilder::class)->build($claimed)]];

        /** @var array<string, array{anyOf: array{array<string, mixed>}}> $properties */
        $properties = $document['components']['schemas']['MeasurementFilters']['properties'];
        $columns    = array_filter($properties, static fn (string $key): bool => !str_starts_with($key, '$'), ARRAY_FILTER_USE_KEY);

        self::assertCount(10, $columns);

        foreach ($columns as $column => $schema) {

            $filters = [$column => $this->canonicalValue($schema['anyOf'][0])];
            $result  = $this->validateAgainstExported($document, '/components/schemas/MeasurementFilters', Helper::toJSON($filters));

            self::assertTrue($result->isValid(), $this->formatErrors($result));

            $this->filter((string) json_encode($filters))->assertOk();
        }
    }

    /**
     * Define the database migrations, adding the measurements table.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::dropIfExists('measurements');
        Schema::create('measurements', static function (Blueprint $table): void {
            $table->id();
            $table->integer('count');
            $table->bigInteger('total');
            $table->double('ratio');
            $table->boolean('active');
            $table->date('day');
            $table->uuid('token');
            $table->string('label');
            $table->timestamps();
        });
    }

    /**
     * Return a canonical value of the exported value schema.
     *
     * @param  array<string, mixed>  $schema
     * @return bool|float|int|string
     */
    private function canonicalValue(array $schema): bool|float|int|string
    {
        return match ([$schema['type'], $schema['format'] ?? $schema['pattern'] ?? null]) {
            ['integer', null]                            => 1,
            ['number', null], ['number', 'float']        => 0.5,
            ['boolean', null]                            => true,
            ['string', 'date']                           => '2024-01-01',
            ['string', ColumnLiteral::DATE_TIME_PATTERN] => '2024-01-01T10:00:00Z',
            ['string', 'uuid']                           => self::FIRST_TOKEN,
            default                                      => 'alpha',
        };
    }

    /**
     * Return the ids of the rows the filters match, in order.
     *
     * @param  string  $filters
     * @return array<int, int>
     */
    private function matchedIds(string $filters): array
    {
        return $this->ids($this->filter($filters)->assertOk());
    }

    /**
     * Issue a request filtered by the raw JSON document.
     *
     * @param  string  $filters
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function filter(string $filters): TestResponse
    {
        return $this->getJson('/measurements?filters=' . urlencode($filters));
    }

    /**
     * Return the ids of the rows in the response, in order.
     *
     * @param  \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>  $response
     * @return array<int, int>
     */
    private function ids(TestResponse $response): array
    {
        return array_map(intval(...), array_column((array) $response->json('data'), 'id'));
    }
}
