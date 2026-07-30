<?php

declare(strict_types = 1);

namespace Tests\Integration\Exports;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\ApiToolkit\Http\Resources\ToolkitCollection;
use SineMacula\ApiToolkit\Http\Resources\ToolkitResource;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Exporter\ExporterServiceProvider;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\ExportableUserResource;
use Tests\TestCase;

/**
 * Regression tests proving that exporting a toolkit resource collection or
 * single item through the ExportNegotiator does not re-query relations that
 * were already eager-loaded before the export began.
 *
 * ResourceCollectionSource and ResourceItemSource do not implement
 * DerivesAggregates, so the engine's withCount/withSum plan is never applied to
 * them. DerivesTabularSchema.tabular() also produces a schema whose with() is
 * empty (relation fields are skipped entirely), so loadMissing() is not called
 * on the pre-loaded collection. The tests confirm both invariants empirically
 * by counting the queries issued exclusively during the streaming phase.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ToolkitResource::class)]
#[CoversClass(ToolkitCollection::class)]
#[CoversClass(ApiResource::class)]
#[CoversTrait(DerivesTabularSchema::class)]
final class ExportEagerLoadRegressionTest extends TestCase
{
    /**
     * Set up each test with seeded data and export routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        $this->seedData();
        $this->registerRoutes();
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

    // -------------------------------------------------------------------------
    // Collection - zero queries during export with pre-loaded relation
    // -------------------------------------------------------------------------

    /**
     * Test that a collection CSV export issues zero queries when the underlying
     * models have their relations already eager-loaded.
     *
     * ResourceCollectionSource.rows() only calls loadMissing() when
     * withRelations() received a non-empty list. DerivesTabularSchema skips all
     * Relation fields, so the engine derives an empty with() list and the load
     * is never triggered. The export also issues no withCount or withSum
     * queries because ResourceCollectionSource does not implement
     * DerivesAggregates. Pre-loaded models therefore reach the CSV writer with
     * zero additional queries.
     *
     * @return void
     */
    public function testCollectionExportDoesNotRequeryEagerLoadedRelations(): void
    {
        // The route handler loads users with their organisation eager-loaded.
        // StreamedResponse defers the callback until streamedContent(), so the
        // DB query log can be reset cleanly between the two phases.
        $response = $this->get('/eager-export/users', ['Accept' => 'text/csv']);

        $response->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $content = $response->streamedContent();

        $queries = DB::getQueryLog();

        DB::disableQueryLog();

        $queryCount   = count($queries);
        $querySummary = implode('; ', array_column($queries, 'query'));

        self::assertSame(
            0,
            $queryCount,
            'Expected 0 queries during CSV collection export but observed '
            . "{$queryCount}: {$querySummary}",
        );

        self::assertStringContainsString('Alice', $content);
        self::assertStringContainsString('Bob', $content);
    }

    // -------------------------------------------------------------------------
    // Item - zero queries during export with pre-loaded relation
    // -------------------------------------------------------------------------

    /**
     * Test that a single-item CSV export issues zero queries when the
     * underlying model has its relation already eager-loaded.
     *
     * ResourceItemSource.rows() only calls loadMissing() when withRelations()
     * received a non-empty list. Since the derived tabular schema carries no
     * with() hints and DerivesAggregates is not implemented, the pre-loaded
     * model is yielded and shaped into a CSV row without any further queries.
     *
     * @return void
     */
    public function testItemExportDoesNotRequeryEagerLoadedRelations(): void
    {
        $user = User::first();

        $response = $this->get('/eager-export/users/' . $user->id, ['Accept' => 'text/csv']);

        $response->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $content = $response->streamedContent();

        $queries = DB::getQueryLog();

        DB::disableQueryLog();

        $queryCount   = count($queries);
        $querySummary = implode('; ', array_column($queries, 'query'));

        self::assertSame(
            0,
            $queryCount,
            'Expected 0 queries during CSV item export but observed '
            . "{$queryCount}: {$querySummary}",
        );

        self::assertStringContainsString('Alice', $content);
    }

    /**
     * Register the exporter service provider alongside the toolkit.
     *
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            ExporterServiceProvider::class,
        ];
    }

    /**
     * Seed the database with an organisation and two users belonging to it.
     *
     * @return void
     */
    private function seedData(): void
    {
        $organisation = Organization::create([
            'name' => 'Acme Corp',
            'slug' => 'acme',
        ]);

        User::create([
            'name'            => 'Alice',
            'email'           => 'alice@example.com',
            'status'          => 'active',
            'organization_id' => $organisation->id,
        ]);

        User::create([
            'name'            => 'Bob',
            'email'           => 'bob@example.com',
            'status'          => 'active',
            'organization_id' => $organisation->id,
        ]);
    }

    /**
     * Register the HTTP routes used by the eager-load regression scenarios.
     *
     * Both routes explicitly eager-load the organisation relation before
     * returning the resource, mirroring what a real repository or service layer
     * would do. The organisation column is a Relation field in
     * ExportableUserResource and is therefore excluded from the tabular
     * schema - confirming that the export neither re-loads it nor uses it to
     * derive aggregates.
     *
     * @return void
     */
    private function registerRoutes(): void
    {
        Route::get('/eager-export/users', static fn (): AnonymousResourceCollection => ExportableUserResource::collection(
            User::with('organization')->paginate(10),
        ));

        Route::get('/eager-export/users/{id}', static function (int $id): ExportableUserResource {
            $user = User::findOrFail($id);
            $user->load('organization');

            return new ExportableUserResource($user);
        });
    }
}
