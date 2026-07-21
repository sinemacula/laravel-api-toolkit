<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Exceptions\PerItemGuardedFieldException;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\ApiToolkit\Http\Resources\Concerns\RowGuardProbe;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Schema\CastRegistry;
use SineMacula\Exporter\Schema\Column;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\GuardedExportResource;
use Tests\Fixtures\Resources\PerItemGuardedExportResource;
use Tests\Fixtures\Resources\ThrowingGuardExportResource;
use Tests\TestCase;

/**
 * Tests for the DerivesTabularSchema trait's transformer and guard handling.
 *
 * Proves that a transformed field mirrors the JSON value in the export, that a
 * request-scoped guard drops the whole column when it hides the field, and that
 * a field guarded per-item is refused at schema-build time.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PerItemGuardedFieldException::class)]
#[CoversClass(RowGuardProbe::class)]
#[CoversTrait(DerivesTabularSchema::class)]
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
     * Test that a request-scoped guard omits the whole column when it hides the
     * field for the current request.
     *
     * @return void
     */
    public function testRequestScopedGuardOmitsColumnWhenHidden(): void
    {
        $user    = new User(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $request = Request::create('/');

        ApiQuery::parse($request);

        $visible = $this->visibleColumnKeys($request, $user);

        self::assertNotContains('secret', $visible);
        self::assertContains('name', $visible);
    }

    /**
     * Test that a request-scoped guard keeps the column when it permits the
     * field for the current request.
     *
     * @return void
     */
    public function testRequestScopedGuardKeepsColumnWhenPermitted(): void
    {
        $user    = new User(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $request = Request::create('/?show=yes');

        ApiQuery::parse($request);

        self::assertContains('secret', $this->visibleColumnKeys($request, $user));
    }

    /**
     * Test that a field guarded per-item is refused at schema-build time with
     * the dedicated exception naming the field.
     *
     * @return void
     */
    public function testPerItemGuardedFieldThrows(): void
    {
        $user    = new User(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $request = Request::create('/');

        ApiQuery::parse($request);

        $this->expectException(PerItemGuardedFieldException::class);
        $this->expectExceptionMessageMatches('/"email".*per_item_guarded_exports/s');

        (new PerItemGuardedExportResource($user))->tabular($request);
    }

    /**
     * Test that a guard that errors when handed a probe row is refused rather
     * than exposed, failing closed.
     *
     * @return void
     */
    public function testGuardThatErrorsOnProbeRowIsRefused(): void
    {
        $user    = new User(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $request = Request::create('/');

        ApiQuery::parse($request);

        $this->expectException(PerItemGuardedFieldException::class);

        (new ThrowingGuardExportResource($user))->tabular($request);
    }

    /**
     * Test that a plain string-accessor field maps to a model-sourced column
     * that reads the accessor path rather than the exposed key.
     *
     * @return void
     */
    public function testStringAccessorFieldMapsToModelSourcedColumn(): void
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

        self::assertCount(1, $columns);
        self::assertSame('contact', $columns[0]->getKey());
        self::assertTrue($columns[0]->usesModelSource());
        self::assertSame('email', $columns[0]->getModelPath());
    }

    /**
     * Test that a non-callable guard entry is skipped during the column
     * visibility check while a relation field is dropped from the schema,
     * leaving a permitting callable guard to decide the remaining column.
     *
     * @return void
     */
    public function testNonCallableGuardIsSkippedDuringVisibilityCheck(): void
    {
        $resource = new class (null) extends ApiResource implements ProvidesTabularExport {
            use DerivesTabularSchema;

            /** @var string */
            public const string RESOURCE_TYPE = 'non_callable_guard_export';

            /** @var array<int, string> */
            protected static array $default = ['flagged', 'related'];

            /**
             * Return a raw schema whose guard list holds a non-callable entry
             * alongside a relation field that must be skipped.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [
                    'flagged' => [
                        'guards' => [123, static fn ($resource, $request): bool => true],
                    ],
                    'related' => [
                        'relation' => 'related',
                    ],
                ];
            }
        };

        $request = Request::create('/');

        ApiQuery::parse($request);

        $columns = $resource->tabular($request)->columns();

        self::assertCount(1, $columns);
        self::assertSame('flagged', $columns[0]->getKey());
        self::assertTrue($columns[0]->isVisible($request));
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
     * Test that a relation field encountered before a scalar field is skipped
     * without abandoning the remaining fields, so the later scalar column is
     * still built.
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
     * Test that a non-callable guard preceding a request-scoped guard
     * that hides the field is skipped rather than ending the check early,
     * so the hiding guard still drops the column.
     *
     * @return void
     */
    public function testNonCallableGuardBeforeHidingGuardStillHidesColumn(): void
    {
        $resource = new class (null) extends ApiResource implements ProvidesTabularExport {
            use DerivesTabularSchema;

            /** @var string */
            public const string RESOURCE_TYPE = 'hiding_guard_after_non_callable_export';

            /** @var array<int, string> */
            protected static array $default = ['gated'];

            /**
             * Return a schema whose guard list holds a non-callable entry
             * before a request-scoped guard that always hides the field.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [
                    'gated' => [
                        'guards' => [123, static fn ($resource, $request): bool => false],
                    ],
                ];
            }
        };

        $request = Request::create('/');

        ApiQuery::parse($request);

        $columns = $resource->tabular($request)->columns();

        self::assertCount(1, $columns);
        self::assertSame('gated', $columns[0]->getKey());
        self::assertFalse($columns[0]->isVisible($request));
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
