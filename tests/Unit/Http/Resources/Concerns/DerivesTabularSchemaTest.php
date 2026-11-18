<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Exceptions\PerItemGuardedFieldException;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Schema\CastRegistry;
use SineMacula\Exporter\Schema\Column;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Enums\UserLevel;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\DeviceResource;
use Tests\Fixtures\Resources\GuardedExportResource;
use Tests\Fixtures\Resources\PerItemGuardedExportResource;
use Tests\TestCase;

/**
 * Tests for the DerivesTabularSchema trait's transformer and guard handling.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PerItemGuardedFieldException::class)]
#[CoversTrait(DerivesTabularSchema::class)]
#[Group('exporter')]
final class DerivesTabularSchemaTest extends TestCase
{
    /**
     * Set up each test with a clean schema cache.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [GuardedExportResource::class]);

        SchemaCompiler::clearCache();
    }

    /**
     * Tear down, clearing the schema compiler cache.
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
     * Test that a transformed scalar field resolves to the same value the JSON
     * response emits.
     *
     * @return void
     */
    public function testTransformedFieldMatchesJsonValue(): void
    {
        $user    = new User(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $request = Request::create('/');

        ApiQuery::parse($request);

        $jsonValue = (new GuardedExportResource($user))->toArray($request)['name'];
        $cell      = $this->columnFor('name', $request, $user)->toCellValue($user, $request, new CastRegistry);

        self::assertSame('ALICE', $cell->raw);
        self::assertSame($jsonValue, $cell->raw);
    }

    /**
     * Test that a caller guard omits the whole column when it hides the field
     * for the current request.
     *
     * @return void
     */
    public function testCallerGuardOmitsColumnWhenHidden(): void
    {
        $user    = new User(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $request = Request::create('/');

        ApiQuery::parse($request);

        $visible = $this->visibleColumnKeys($request, $user);

        self::assertNotContains('secret', $visible);
        self::assertContains('name', $visible);
    }

    /**
     * Test that a caller guard keeps the column when it permits the field for
     * the current request.
     *
     * @return void
     */
    public function testCallerGuardKeepsColumnWhenPermitted(): void
    {
        $user    = new User(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $request = Request::create('/?show=yes');

        ApiQuery::parse($request);

        self::assertContains('secret', $this->visibleColumnKeys($request, $user));
    }

    /**
     * Test that a field with a row guard is refused at schema-build time with
     * the dedicated exception naming the field.
     *
     * @return void
     */
    public function testRowGuardedFieldThrows(): void
    {
        $user    = new User(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $request = Request::create('/');

        ApiQuery::parse($request);

        $this->expectException(PerItemGuardedFieldException::class);
        $this->expectExceptionMessageMatches('/"email".*per_item_guarded_exports/s');

        (new PerItemGuardedExportResource($user))->tabular($request);
    }

    /**
     * Provide the row guard shapes a resource is written with.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rowGuardShapes(): iterable
    {
        $shapes = [
            'request only'               => static fn ($resource, $request): bool => $request?->query('show') === 'yes',
            'typed resource and request' => static fn (ApiResource $resource, ?Request $request): bool => $request?->query('show') === 'yes',
            'type checked then row read' => static fn (mixed $resource): bool => !$resource instanceof ApiResource || data_get($resource->resource, 'status') === 'active',
            'throwing'                   => static fn (): never => throw new \RuntimeException('needs a real row'),
        ];

        foreach ($shapes as $label => $guard) {
            yield $label => [['guards' => [$guard]]];
        }
    }

    /**
     * Test that every row guard is refused without being run, whatever it
     * reads, since only the row it is handed could prove it reads none.
     *
     * A guard run against anything but a resource would throw on its own.
     *
     * @param  array<string, mixed>  $secret
     * @return void
     */
    #[DataProvider('rowGuardShapes')]
    public function testEveryRowGuardIsRefusedWithoutBeingRun(array $secret): void
    {
        $request = Request::create('/?show=yes');

        ApiQuery::parse($request);

        $this->expectException(PerItemGuardedFieldException::class);
        $this->expectExceptionMessage('The "secret" field on the "row_guard_shape_exports" resource carries a row guard');

        $this->resourceWithSecret($secret)->tabular($request);
    }

    /**
     * Test that a caller guard sees the export's request when the exporter asks
     * whether the column is visible, and only then.
     *
     * @return void
     */
    public function testCallerGuardIsAskedAboutTheExportRequestOnly(): void
    {
        $request = Request::create('/');
        /** @var \ArrayObject<int, \Illuminate\Http\Request|null> $received */
        $received = new \ArrayObject;

        ApiQuery::parse($request);

        $resource = $this->resourceWithSecret(['caller_guards' => [static function (?Request $request) use ($received): bool {

            $received[] = $request;

            return false;
        }]]);

        $column = $resource->tabular($request)->columns()[1];

        self::assertSame([], $received->getArrayCopy());
        self::assertFalse($column->isVisible($request));
        self::assertSame([$request], $received->getArrayCopy());
    }

    /**
     * Test that a plain string-accessor field maps to a column that reads the
     * accessor path rather than the exposed key.
     *
     * @return void
     */
    public function testStringAccessorFieldReadsTheAccessorPath(): void
    {
        $resource = new class (null) extends ApiResource implements ProvidesTabularExport {
            use DerivesTabularSchema;

            /** @var string */
            public const string RESOURCE_TYPE = 'string_accessor_export';

            /** @var array<int, string> */
            protected static array $default = ['contact'];

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::accessor('contact', 'email'),
                );
            }
        };

        $request = Request::create('/');

        ApiQuery::parse($request);

        $columns = $resource->tabular($request)->columns();
        $user    = new User(['name' => 'Alice', 'email' => 'alice@example.com']);

        self::assertCount(1, $columns);
        self::assertSame('contact', $columns[0]->getKey());
        self::assertSame('alice@example.com', $columns[0]->toCellValue($user, $request, new CastRegistry)->raw);
    }

    /**
     * Test that a plain field holding a pure enum is written as its lowercase
     * wire name, and a backed enum as its value.
     *
     * @return void
     */
    public function testPlainEnumFieldIsWrittenAsTheValueAResponseCarries(): void
    {
        $row     = ['id' => 1, 'name' => 'Phone', 'platform' => Platform::MOBILE_APP, 'status' => UserLevel::SILVER];
        $request = Request::create('/');

        ApiQuery::parse($request);

        $cells = array_map(
            static fn (Column $column): mixed => $column->toCellValue($row, $request, new CastRegistry)->raw,
            (new DeviceResource(null))->tabular($request)->columns(),
        );

        self::assertSame([1, 'Phone', 'mobile_app', 2], $cells);
    }

    /**
     * Test that an active field selection narrows the columns to exactly the
     * requested subset, intersected with the schema, and excludes the rest.
     *
     * @return void
     */
    public function testFieldSelectionLimitsColumnsToRequestedSubset(): void
    {
        $user    = new User(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $request = Request::create('/?fields[guarded_exports]=name');

        ApiQuery::parse($request);

        $keys = array_map(
            static fn (Column $column): string => $column->getKey(),
            (new GuardedExportResource($user))->tabular($request)->columns(),
        );

        self::assertSame(['name'], $keys);
    }

    /**
     * Test that a relation field before a scalar field is skipped without
     * abandoning the later scalar column.
     *
     * @return void
     */
    public function testRelationFieldIsSkippedButLaterScalarStillIncluded(): void
    {
        $resource = new class (null) extends ApiResource implements ProvidesTabularExport {
            use DerivesTabularSchema;

            /** @var string */
            public const string RESOURCE_TYPE = 'relation_before_scalar_export';

            /** @var array<int, string> */
            protected static array $default = ['related', 'flagged'];

            /**
             * Return a schema whose relation field precedes a scalar field.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [
                    'related' => [
                        'relation' => 'related',
                    ],
                    'flagged' => [],
                ];
            }
        };

        $request = Request::create('/');

        ApiQuery::parse($request);

        $columns = $resource->tabular($request)->columns();

        self::assertCount(1, $columns);
        self::assertSame('flagged', $columns[0]->getKey());
    }

    /**
     * Build an exportable resource whose secret field carries the given raw
     * definition.
     *
     * @param  array<string, mixed>  $secret
     * @return \SineMacula\Exporter\Contracts\ProvidesTabularExport
     */
    private function resourceWithSecret(array $secret): ProvidesTabularExport
    {
        $resource = new class (null) extends ApiResource implements ProvidesTabularExport {
            use DerivesTabularSchema;

            /** @var string */
            public const string RESOURCE_TYPE = 'row_guard_shape_exports';

            /** @var array<string, mixed> */
            public static array $secret = [];

            /** @var array<int, string> */
            protected static array $default = ['id', 'secret'];

            /**
             * Return a schema whose secret field carries the given definition.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return ['id' => [], 'secret' => self::$secret];
            }
        };

        $resource::$secret = $secret;

        return $resource;
    }

    /**
     * Resolve the column with the given key from the guarded export schema.
     *
     * @param  string  $key
     * @param  \Illuminate\Http\Request  $request
     * @param  \Tests\Fixtures\Models\User  $user
     * @return \SineMacula\Exporter\Schema\Column
     */
    private function columnFor(string $key, Request $request, User $user): Column
    {
        foreach ((new GuardedExportResource($user))->tabular($request)->columns() as $column) {
            if ($column->getKey() === $key) {
                return $column;
            }
        }

        self::fail(sprintf('No column found for key "%s".', $key));
    }

    /**
     * Get the keys of the columns visible for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Tests\Fixtures\Models\User  $user
     * @return array<int, string>
     */
    private function visibleColumnKeys(Request $request, User $user): array
    {
        $keys = [];

        foreach ((new GuardedExportResource($user))->tabular($request)->columns() as $column) {

            if (!$column->isVisible($request)) {
                continue;
            }

            $keys[] = $column->getKey();
        }

        return $keys;
    }
}
