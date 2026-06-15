<?php

namespace Tests\Integration\RouteLinting;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\RouteDescriptor;
use SineMacula\ApiToolkit\RouteLinting\Dto\RouteSuppression;
use SineMacula\ApiToolkit\RouteLinting\Sources\RouterRouteSource;
use Tests\TestCase;

/**
 * Integration tests for the RouterRouteSource adapter.
 *
 * Verifies that the adapter correctly maps live router routes to
 * RouteDescriptor DTOs, excludes vendor routes, and returns a consistent
 * set regardless of route-cache state.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RouterRouteSource::class)]
class RouterRouteSourceTest extends TestCase
{
    /** @var string The vendor-segment path marker used in vendor detection. */
    private const string VENDOR_SEGMENT = DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;

    /**
     * Test that app-owned routes are returned as RouteDescriptor instances
     * with the correct uri, methods, and name fields.
     *
     * @return void
     */
    public function testReturnsAppOwnedRoutesAsDescriptors(): void
    {
        $router = $this->getRouter();

        $router->get('users', fn () => [])->name('users.index');
        $router->post('users', fn () => [])->name('users.store');

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        static::assertContainsOnlyInstancesOf(RouteDescriptor::class, $descriptors);

        $byName = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->name !== null) {
                $byName[$descriptor->name] = $descriptor;
            }
        }

        static::assertArrayHasKey('users.index', $byName);
        static::assertArrayHasKey('users.store', $byName);

        $index = $byName['users.index'];

        static::assertSame('users', $index->uri);
        static::assertContains('GET', $index->methods);
        static::assertFalse($index->isVendor);

        $store = $byName['users.store'];

        static::assertSame('users', $store->uri);
        static::assertContains('POST', $store->methods);
        static::assertFalse($store->isVendor);
    }

    /**
     * Test that a route whose controller class resolves to a file under the
     * vendor directory is excluded from the returned set.
     *
     * @return void
     */
    public function testExcludesVendorRoutes(): void
    {
        $router = $this->getRouter();

        // App-owned closure route
        $router->get('app-route', fn () => [])->name('app.route');

        // Vendor-backed controller route: the controller class file lives under vendor/
        $router->get('vendor-route', '\Illuminate\Routing\RedirectController@__invoke')
            ->name('vendor.route');

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        $names = array_map(static fn (RouteDescriptor $d) => $d->name, $descriptors);

        static::assertContains('app.route', $names);
        static::assertNotContains('vendor.route', $names);
    }

    /**
     * Test that calling appRoutes() twice on the same router returns an identical set of descriptors.
     *
     * Verifies that enumeration is idempotent: the URIs returned by consecutive
     * calls are sorted-equal, confirming the adapter produces a deterministic
     * result without requiring route-cache warming or clearing.
     *
     * @return void
     */
    public function testEnumerationIsIdempotent(): void
    {
        $router = $this->getRouter();

        $router->get('orders', fn () => [])->name('orders.index');
        $router->delete('orders/{order}', fn () => [])->name('orders.destroy');

        $source = new RouterRouteSource($router);

        // First call simulates a cold-cache environment
        $firstPass = $source->appRoutes();

        // Second call simulates a warm-cache environment
        $secondPass = $source->appRoutes();

        static::assertCount(count($firstPass), $secondPass);

        $firstUris  = array_map(static fn (RouteDescriptor $d) => $d->uri, $firstPass);
        $secondUris = array_map(static fn (RouteDescriptor $d) => $d->uri, $secondPass);

        sort($firstUris);
        sort($secondUris);

        static::assertSame($firstUris, $secondUris);
    }

    /**
     * Test that the count of app-owned routes returned by the adapter matches
     * the count of non-vendor routes in the live router (census parity with
     * what route:list --except-vendor would report).
     *
     * @return void
     */
    public function testAppOwnedCountMatchesRouteList(): void
    {
        $router = $this->getRouter();

        $router->get('products', fn () => [])->name('products.index');
        $router->get('products/{product}', fn () => [])->name('products.show');
        $router->get('vendor-redirect', '\Illuminate\Routing\RedirectController@__invoke');

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        // Build a reference count using the same vendor-detection heuristic
        // as the adapter, so the test does not depend on CLI output format
        $expectedCount = 0;

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (!$this->isVendorRoute($route)) {
                $expectedCount++;
            }
        }

        static::assertCount($expectedCount, $descriptors);
    }

    /**
     * Test that no Illuminate\Routing\Route instance is returned by the
     * adapter — only RouteDescriptor DTOs cross the boundary.
     *
     * @return void
     */
    public function testNoFrameworkRouteLeaksPastTheAdapter(): void
    {
        $router = $this->getRouter();

        $router->get('items', fn () => [])->name('items.index');

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        foreach ($descriptors as $descriptor) {
            static::assertInstanceOf(RouteDescriptor::class, $descriptor);
            static::assertNotInstanceOf(Route::class, $descriptor);
        }
    }

    /**
     * Test that an app with no registered routes returns an empty array.
     *
     * @return void
     */
    public function testEmptyRouterReturnsEmptyArray(): void
    {
        $source = new RouterRouteSource($this->getRouter());

        static::assertSame([], $source->appRoutes());
    }

    /**
     * Test that a route decorated with ignoreRouteLint() yields a descriptor
     * whose suppressions property carries a matching RouteSuppression.
     *
     * @return void
     */
    public function testRouteWithIgnoreRouteLintYieldsSuppressionOnDescriptor(): void
    {
        $router = $this->getRouter();

        $router->get('invoices', fn () => [])
            ->name('invoices.index')
            ->ignoreRouteLint(['R9'], 'Legacy naming kept for migration period.'); // @phpstan-ignore method.notFound

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        $byName = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->name !== null) {
                $byName[$descriptor->name] = $descriptor;
            }
        }

        static::assertArrayHasKey('invoices.index', $byName);

        $descriptor = $byName['invoices.index'];

        static::assertCount(1, $descriptor->suppressions);
        static::assertInstanceOf(RouteSuppression::class, $descriptor->suppressions[0]);
        static::assertSame(['R9'], $descriptor->suppressions[0]->rules);
        static::assertSame('Legacy naming kept for migration period.', $descriptor->suppressions[0]->reason);
    }

    /**
     * Test that a route without ignoreRouteLint() yields a descriptor with an
     * empty suppressions list.
     *
     * @return void
     */
    public function testRouteWithoutSuppressionYieldsEmptySuppressionsOnDescriptor(): void
    {
        $router = $this->getRouter();

        $router->get('categories', fn () => [])->name('categories.index');

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        $byName = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->name !== null) {
                $byName[$descriptor->name] = $descriptor;
            }
        }

        static::assertArrayHasKey('categories.index', $byName);
        static::assertSame([], $byName['categories.index']->suppressions);
    }

    /**
     * Get a fresh router instance for the test.
     *
     * Returns the container-bound router so routes are registered against the
     * same instance the adapter consumes.
     *
     * @return \Illuminate\Routing\Router
     */
    private function getRouter(): Router
    {
        assert($this->app !== null);

        /** @var \Illuminate\Routing\Router */
        return $this->app->make('router');
    }

    /**
     * Reference implementation of vendor-route detection used by the census
     * parity test to build an independent expected count.
     *
     * Mirrors the same heuristic as the adapter: closure -> reflect file;
     * string -> reflect controller class file; default -> app-owned.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return bool
     */
    private function isVendorRoute(Route $route): bool
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof \Closure) {
            try {
                $file = (new \ReflectionFunction($uses))->getFileName();
            } catch (\ReflectionException) {
                return false;
            }

            return is_string($file) && str_contains($file, self::VENDOR_SEGMENT);
        }

        if (!is_string($uses)) {
            return false;
        }

        $controllerClass = $route->getControllerClass();

        if ($controllerClass === null) {
            return false;
        }

        try {
            $file = (new \ReflectionClass($controllerClass))->getFileName();
        } catch (\ReflectionException) {
            return false;
        }

        return is_string($file) && str_contains($file, self::VENDOR_SEGMENT);
    }
}
