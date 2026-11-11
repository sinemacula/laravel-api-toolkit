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
 * Regression tests that exports do not re-query eager-loaded relations.
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

    /**
     * Test that a collection export issues no queries for loaded relations.
     *
     * @return void
     */
    public function testCollectionExportDoesNotRequeryEagerLoadedRelations(): void
    {
        // The callback is deferred, so the query log is reset between phases
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

    /**
     * Test that an item export issues no queries for an eager-loaded relation.
     *
     * @return void
     */
    public function testItemExportDoesNotRequeryEagerLoadedRelations(): void
    {
        $user = User::first();

        self::assertNotNull($user);

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
     * Register the routes used by the eager-load regression scenarios.
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
