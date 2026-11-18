<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\Resolution\ColumnTypeMapper;
use SineMacula\ApiToolkit\OpenApi\Resolution\FieldTypeResolver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Models\TypedWidget;
use Tests\Fixtures\OpenApi\TypedWidgetController;
use Tests\Fixtures\Resources\TypedWidgetResource;
use Tests\TestCase;

/**
 * Parity test between each exported field type and the value the resource
 * renders for it, on the engine the suite runs against.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnTypeMapper::class)]
#[CoversClass(FieldTypeResolver::class)]
final class OpenApiFieldTypeParityTest extends TestCase
{
    use ValidatesOpenApiDocuments;

    /** @var array<string, bool> The schema of a value with no fixed shape */
    private const array UNDOCUMENTED = ['x-undocumented' => true];

    /** @var array<string, mixed> The values stored in the columns with a nullable twin */
    private const array TWINNED_VALUES = [
        'price'       => '12.50',
        'weight'      => '7.25',
        'released_on' => '2020-02-03',
        'seen_at'     => '2026-01-01 09:00:00',
        'opens_at'    => '09:30:00',
        'active'      => true,
        'flagged'     => true,
        'settings'    => ['a' => 1],
        'aliases'     => ['x', 'y'],
        'metadata'    => ['k' => 'v'],
        'reference'   => '0190b3c4-6f1e-7a2b-9c3d-4e5f6a7b8c9d',
        'status'      => 'active',
    ];

    /**
     * Set up each test with the typed widget resource documented and routed,
     * and an encryption key for the encrypted casts.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        config()->set('api-toolkit.resources.resource_map', [TypedWidget::class => TypedWidgetResource::class]);

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));

        Route::middleware(ParseApiQuery::class)->get('typed-widgets/{widget}', [TypedWidgetController::class, 'show']);
    }

    /**
     * Tear down each test, clearing the compiled schema cache.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that every field exports the schema of the value Eloquent returns
     * for its column and cast on this engine.
     *
     * @return void
     */
    public function testEveryFieldExportsTheSchemaOfItsValue(): void
    {
        /** @var array<string, mixed> $properties */
        $properties = $this->exportedDocument()['components']['schemas']['TypedWidget']['properties'];
        $expected   = $this->expectedProperties();

        unset($properties['_type']);
        ksort($properties);
        ksort($expected);

        self::assertSame($expected, $properties);
    }

    /**
     * Provide whether the nullable twins hold values or null.
     *
     * @return iterable<string, array{bool}>
     */
    public static function twinProvider(): iterable
    {
        yield 'twins holding values' => [true];
        yield 'twins holding null' => [false];
    }

    /**
     * Test that each rendered value validates against its field's exported
     * schema, reporting every field that does not.
     *
     * @param  bool  $filled
     * @return void
     */
    #[DataProvider('twinProvider')]
    public function testEveryRenderedValueMatchesItsExportedSchema(bool $filled): void
    {
        $document = $this->exportedDocument();
        $payload  = $this->getJson('/typed-widgets/' . $this->createWidget($filled)->id)->assertOk()->json('data');
        $failures = [];

        self::assertIsArray($payload);
        self::assertEqualsCanonicalizing(['_type', ...array_keys($this->expectedProperties())], array_keys($payload));

        foreach ($payload as $field => $value) {

            $result = $this->validateAgainstExported($document, '/components/schemas/TypedWidget/properties/' . $field, $value);

            if ($result->isValid()) {
                continue;
            }

            $failures[$field] = $this->formatErrors($result);
        }

        self::assertSame([], $failures);
    }

    /**
     * Build the schema expected for each field on the engine under test.
     *
     * PostgreSQL alone reports a boolean and a uuid column by type, a float
     * column fetches as a string there before PHP 8.4, and MariaDB reports a
     * JSON column as longtext.
     *
     * @return array<string, array<string, mixed>>
     */
    private function expectedProperties(): array
    {
        $connection = DB::connection();
        $postgres   = $connection->getDriverName() === 'pgsql';
        $nativeJson = $postgres || ($connection instanceof MySqlConnection && !$connection->isMaria());
        $boolean    = $postgres ? 'boolean' : 'integer';
        $uuid       = $postgres ? ['format' => 'uuid'] : [];
        $float      = ['type' => $postgres && PHP_VERSION_ID < 80400 ? 'string' : 'number'];
        $json       = $nativeJson ? self::UNDOCUMENTED : ['type' => 'string'];
        $status     = ['$ref' => '#/components/schemas/UserStatus'];

        return [
            'id'                                => ['type' => 'integer'],
            'user_id'                           => ['type' => ['integer', 'null']],
            'price'                             => ['type' => 'string'],
            'nullable_price'                    => ['type' => ['string', 'null']],
            'weight'                            => self::UNDOCUMENTED,
            'nullable_weight'                   => self::UNDOCUMENTED,
            'released_on'                       => ['type' => 'string', 'format' => 'date-time'],
            'nullable_released_on'              => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'seen_at'                           => ['type' => 'string'],
            'nullable_seen_at'                  => ['type' => ['string', 'null']],
            'opens_at'                          => ['type' => 'string'],
            'nullable_opens_at'                 => ['type' => ['string', 'null']],
            'active'                            => ['type' => 'boolean'],
            'nullable_active'                   => ['type' => ['boolean', 'null']],
            'flagged'                           => ['type' => $boolean],
            'nullable_flagged'                  => ['type' => [$boolean, 'null']],
            'settings'                          => self::UNDOCUMENTED,
            'nullable_settings'                 => self::UNDOCUMENTED,
            'aliases'                           => self::UNDOCUMENTED,
            'nullable_aliases'                  => self::UNDOCUMENTED,
            'metadata'                          => self::UNDOCUMENTED,
            'nullable_metadata'                 => self::UNDOCUMENTED,
            'reference'                         => ['type' => 'string', ...$uuid],
            'nullable_reference'                => ['type' => ['string', 'null'], ...$uuid],
            'status'                            => $status,
            'nullable_status'                   => ['anyOf' => [$status, ['type' => 'null']]],
            'type_big_integer'                  => ['type' => 'integer'],
            'type_integer'                      => ['type' => 'integer'],
            'type_medium_integer'               => ['type' => 'integer'],
            'type_small_integer'                => ['type' => 'integer'],
            'type_tiny_integer'                 => ['type' => 'integer'],
            'type_float'                        => $float,
            'type_real'                         => $float,
            'type_double'                       => $float,
            'type_char'                         => ['type' => 'string'],
            'type_string'                       => ['type' => 'string'],
            'type_tiny_text'                    => ['type' => 'string'],
            'type_text'                         => ['type' => 'string'],
            'type_medium_text'                  => ['type' => 'string'],
            'type_long_text'                    => ['type' => 'string'],
            'type_enum'                         => ['type' => 'string'],
            'type_json'                         => $json,
            'type_jsonb'                        => $json,
            'type_ulid'                         => ['type' => 'string'],
            'type_date'                         => ['type' => 'string', 'format' => 'date'],
            'type_date_time_tz'                 => ['type' => 'string'],
            'type_time_tz'                      => ['type' => 'string'],
            'type_timestamp'                    => ['type' => 'string'],
            'type_timestamp_tz'                 => ['type' => 'string'],
            'type_binary'                       => self::UNDOCUMENTED,
            'cast_integer'                      => ['type' => 'integer'],
            'cast_float'                        => ['type' => 'number'],
            'cast_string'                       => ['type' => 'string'],
            'cast_timestamp'                    => ['type' => 'integer'],
            'cast_datetime'                     => ['type' => 'string', 'format' => 'date-time'],
            'cast_immutable_date'               => ['type' => 'string', 'format' => 'date-time'],
            'cast_formatted_date'               => ['type' => 'string', 'format' => 'date-time'],
            'cast_formatted_immutable_datetime' => ['type' => 'string', 'format' => 'date-time'],
            'cast_encrypted'                    => ['type' => 'string'],
            'cast_hashed'                       => ['type' => 'string'],
            'cast_stringable'                   => ['type' => 'string'],
            'cast_uri'                          => ['type' => 'string'],
            'cast_json'                         => self::UNDOCUMENTED,
            'cast_object'                       => self::UNDOCUMENTED,
            'cast_encrypted_object'             => self::UNDOCUMENTED,
            'cast_array_object'                 => self::UNDOCUMENTED,
            'cast_custom'                       => self::UNDOCUMENTED,
            'created_at'                        => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'updated_at'                        => ['type' => ['string', 'null'], 'format' => 'date-time'],
        ];
    }

    /**
     * Save a typed widget, its nullable twins holding the same values or null.
     *
     * The binary column stays null, since PostgreSQL fetches it as a stream.
     *
     * @param  bool  $filled
     * @return \Tests\Fixtures\Models\TypedWidget
     */
    private function createWidget(bool $filled): TypedWidget
    {
        $twins = array_combine(
            array_map(static fn (string $column): string => 'nullable_' . $column, array_keys(self::TWINNED_VALUES)),
            $filled ? self::TWINNED_VALUES : array_fill(0, count(self::TWINNED_VALUES), null),
        );

        return TypedWidget::create([
            ...self::TWINNED_VALUES,
            ...$twins,
            'type_big_integer'                  => 5000000000,
            'type_integer'                      => 42,
            'type_medium_integer'               => 4200,
            'type_small_integer'                => 420,
            'type_tiny_integer'                 => 7,
            'type_float'                        => 1.5,
            'type_real'                         => 2.5,
            'type_double'                       => 3.25,
            'type_char'                         => 'abc',
            'type_string'                       => 'string',
            'type_tiny_text'                    => 'tiny',
            'type_text'                         => 'text',
            'type_medium_text'                  => 'medium',
            'type_long_text'                    => 'long',
            'type_enum'                         => 'small',
            'type_json'                         => '{"a":1}',
            'type_jsonb'                        => '{"b":2}',
            'type_ulid'                         => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'type_date'                         => '2020-02-03',
            'type_date_time_tz'                 => '2026-01-01 09:00:00',
            'type_time_tz'                      => '09:30:00',
            'type_timestamp'                    => '2026-01-01 09:00:00',
            'type_timestamp_tz'                 => '2026-01-01 09:00:00',
            'cast_integer'                      => '42',
            'cast_float'                        => 2.5,
            'cast_string'                       => 7,
            'cast_timestamp'                    => '2026-01-02 03:04:05',
            'cast_datetime'                     => '2026-01-02 03:04:05',
            'cast_immutable_date'               => '2020-02-03',
            'cast_formatted_date'               => '2020-02-03',
            'cast_formatted_immutable_datetime' => '2026-01-02 03:04:05',
            'cast_encrypted'                    => 'secret',
            'cast_hashed'                       => 'password',
            'cast_stringable'                   => 'stringable',
            'cast_uri'                          => 'https://example.com/path',
            'cast_json'                         => ['a' => 1],
            'cast_object'                       => ['b' => 2],
            'cast_encrypted_object'             => ['c' => 3],
            'cast_array_object'                 => ['d' => 4],
            'cast_custom'                       => 'custom',
        ]);
    }

    /**
     * Export the document the components are read from.
     *
     * @return array<string, mixed>
     */
    private function exportedDocument(): array
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents $exporter */
        $exporter = $this->app->make(ExportOpenApiComponents::class);

        return $exporter->export()->document;
    }
}
