<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\PerItemGuardedFieldException;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\ApiToolkit\Http\Resources\ToolkitCollection;
use SineMacula\ApiToolkit\Http\Resources\ToolkitResource;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Exporter\ExporterServiceProvider;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\GuardedExportResource;
use Tests\Fixtures\Resources\PerItemGuardedExportResource;
use Tests\Fixtures\Resources\ThrowingGuardExportResource;
use Tests\TestCase;

/**
 * Feature tests proving the data-leak guarantees of field guards inside
 * content-negotiated exports, observed on the streamed bytes and the aborted
 * response.
 *
 * A request-scoped guard drops or keeps its whole column in the streamed CSV
 * depending on the request, so a guarded value never reaches the wire unless
 * the request permits it. A row-dependent or fail-closed guard cannot be
 * honoured by a flat tabular schema, so the export aborts before any row is
 * streamed, surfacing a non-200 error with a non-tabular content type and no
 * leaked rows.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ToolkitCollection::class)]
#[CoversClass(ToolkitResource::class)]
#[CoversClass(ApiResource::class)]
#[CoversClass(ApiExceptionHandler::class)]
#[CoversClass(PerItemGuardedFieldException::class)]
#[CoversTrait(DerivesTabularSchema::class)]
final class GuardedExportTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with the exporter bound, the handler wired, seeded users
     * and the guarded export routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        $this->registerApiExceptionHandler();

        $this->seedUsers();
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
     * Test that a request-scoped guard omits its column and every guarded value
     * from the streamed CSV by default, and includes them when the request
     * permits the field.
     *
     * @return void
     */
    public function testRequestScopedGuardDropsAndKeepsColumnInStreamedCsv(): void
    {
        $bare = $this->get('/guarded-export/users', ['Accept' => 'text/csv']);

        $bare->assertOk();
        $bare->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $bareContent = $bare->streamedContent();

        self::assertStringContainsString('ALICE', $bareContent);
        self::assertStringNotContainsStringIgnoringCase('secret', $bareContent);
        self::assertStringNotContainsString('CLASSIFIED-1', $bareContent);
        self::assertStringNotContainsString('CLASSIFIED-2', $bareContent);

        $permitted = $this->get('/guarded-export/users?show=yes', ['Accept' => 'text/csv']);

        $permitted->assertOk();
        $permitted->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $permittedContent = $permitted->streamedContent();

        self::assertStringContainsStringIgnoringCase('secret', $permittedContent);
        self::assertStringContainsString('CLASSIFIED-1', $permittedContent);
        self::assertStringContainsString('CLASSIFIED-2', $permittedContent);
    }

    /**
     * Test that a row-dependent guard and a fail-closed guard abort the export
     * before any row is streamed, returning a non-200 error with a non-tabular
     * content type and no leaked values, for both the item and collection
     * forms.
     *
     * @return void
     */
    public function testRowDependentAndFailClosedGuardsAbortWithoutLeakingRows(): void
    {
        $routes = [
            '/guarded-abort/per-item',
            '/guarded-abort/per-item/1',
            '/guarded-abort/throwing',
            '/guarded-abort/throwing/1',
        ];

        foreach ($routes as $route) {

            $response = $this->get($route, ['Accept' => 'text/csv']);

            $response->assertServerError();

            $contentType = (string) $response->headers->get('Content-Type');

            self::assertStringNotContainsStringIgnoringCase('csv', $contentType, "Route {$route} must not return a tabular content type.");
            self::assertStringNotContainsStringIgnoringCase('tab-separated-values', $contentType, "Route {$route} must not return a tabular content type.");

            $response->assertDontSee('alice@example.com');
            $response->assertDontSee('bob@example.com');
        }
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
     * Seed the database with two users of differing status.
     *
     * @return void
     */
    private function seedUsers(): void
    {
        User::create([
            'name'   => 'Alice',
            'email'  => 'alice@example.com',
            'status' => 'active',
        ]);

        User::create([
            'name'   => 'Bob',
            'email'  => 'bob@example.com',
            'status' => 'inactive',
        ]);
    }

    /**
     * Register the guarded export routes.
     *
     * The streaming route attaches a distinctive secret value to each row so
     * the guarded column can be proven present or absent in the streamed bytes.
     * The aborting routes carry guards that cannot be honoured on a flat
     * tabular schema, so building their export throws before streaming.
     *
     * @return void
     */
    private function registerRoutes(): void
    {
        Route::get('/guarded-export/users', static function (): AnonymousResourceCollection {
            $users = User::all();

            $users->each(static fn (User $user) => $user->setAttribute('secret', 'CLASSIFIED-' . $user->id));

            return GuardedExportResource::collection($users);
        });

        Route::get('/guarded-abort/per-item', static fn (): AnonymousResourceCollection => PerItemGuardedExportResource::collection(User::paginate(10)));

        Route::get('/guarded-abort/per-item/{id}', static fn (int $id): PerItemGuardedExportResource => new PerItemGuardedExportResource(User::findOrFail($id)));

        Route::get('/guarded-abort/throwing', static fn (): AnonymousResourceCollection => ThrowingGuardExportResource::collection(User::paginate(10)));

        Route::get('/guarded-abort/throwing/{id}', static fn (int $id): ThrowingGuardExportResource => new ThrowingGuardExportResource(User::findOrFail($id)));
    }
}
