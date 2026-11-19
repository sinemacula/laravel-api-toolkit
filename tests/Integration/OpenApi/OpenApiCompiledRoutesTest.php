<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Foundation\Application;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRouteCollector;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathPlainController;
use Tests\Fixtures\OpenApi\PathRequestBodyController;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Exports from a cached route table, as `route:cache` leaves it, against the
 * same routes uncached.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DocumentedRouteCollector::class)]
#[CoversClass(DocumentableRouteFilter::class)]
final class OpenApiCompiledRoutesTest extends TestCase
{
    use ValidatesOpenApiDocuments;

    /** @var list<string> The audiences exported */
    private const array AUDIENCES = ['public', 'internal', 'partner'];

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        Config::set('auth.defaults.guard', 'web');
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);
        Config::set('api-toolkit.openapi.audiences', [
            'public'   => [],
            'internal' => [],
            'partner'  => ['posture' => 'allowlist'],
        ]);
        Config::set('api-toolkit.openapi.exclude.namespaces', [...DocumentableRouteFilter::DEFAULT_NAMESPACES, __NAMESPACE__]);

        $this->registerRoutes();
    }

    /**
     * Test that every audience documents the same operations once the route
     * table is cached.
     *
     * @return void
     */
    public function testCachedRoutesExportTheDocumentsUncachedRoutesDo(): void
    {
        $uncached = $this->exportAudiences();

        self::assertSame(['/users', '/users/{user}', '/status'], $this->paths($uncached['public']));
        self::assertSame(['/users', '/status'], $this->paths($uncached['internal']));
        self::assertSame(['/users/{user}'], $this->paths($uncached['partner']));

        $this->cacheRoutes();

        self::assertSame(json_encode($uncached), json_encode($this->exportAudiences()));
    }

    /**
     * Register controller, closure, route-file and fallback routes.
     *
     * @return void
     */
    private function registerRoutes(): void
    {
        $router = $this->router();

        $router->get('users', [PathFixtureController::class, 'index'])
            ->middleware(['auth', 'throttle:60,1'])
            ->name('users.index')
            ->notDocumentedIn('partner'); // @phpstan-ignore method.notFound
        $router->get('users/{user}', [PathFixtureController::class, 'show'])
            ->whereNumber('user')
            ->notDocumentedIn('internal') // @phpstan-ignore method.notFound
            ->documentedIn('partner');
        $router->put('users/{user}', [PathRequestBodyController::class, 'updateBound'])->whereNumber('user')->notDocumentedIn('internal'); // @phpstan-ignore method.notFound
        $router->get('reports', [PathPlainController::class, 'report'])->undocumented(); // @phpstan-ignore method.notFound
        $router->get('internal-health', static fn (): null => null);
        $router->group([], __DIR__ . '/../../Fixtures/OpenApi/Routes/closures.inc');
        $router->fallback([PathPlainController::class, 'index']);
    }

    /**
     * Cache the route table as `route:cache` does and load it back.
     *
     * @return void
     */
    private function cacheRoutes(): void
    {
        $router = $this->router();
        $routes = $router->getRoutes();

        assert($routes instanceof RouteCollection);

        foreach ($routes->getRoutes() as $route) {
            $route->prepareForSerialization();
        }

        $compiled = unserialize(serialize($routes->compile()));

        assert(is_array($compiled));

        $router->setCompiledRoutes($compiled);

        self::assertInstanceOf(CompiledRouteCollection::class, $router->getRoutes());
    }

    /**
     * Export every audience, asserting each document is valid.
     *
     * @return array<string, array<string, mixed>>
     */
    private function exportAudiences(): array
    {
        $exporter  = $this->app()->make(ExportOpenApiComponents::class);
        $documents = [];

        foreach (self::AUDIENCES as $audience) {

            $documents[$audience] = $exporter->export($audience)->document;

            $this->assertValidDocument($documents[$audience]);
        }

        return $documents;
    }

    /**
     * List the paths a document documents.
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function paths(array $document): array
    {
        self::assertIsArray($document['paths']);

        return array_map('strval', array_keys($document['paths']));
    }

    /**
     * Resolve the application router.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        return $this->app()->make(Router::class);
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function app(): Application
    {
        assert($this->app instanceof Application);

        return $this->app;
    }
}
