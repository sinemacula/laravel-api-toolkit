<?php

namespace Tests\Integration\RouteLinting;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\RouteDescriptor;
use SineMacula\ApiToolkit\RouteLinting\Dto\RouteSuppression;
use SineMacula\ApiToolkit\RouteLinting\Sources\RouterRouteSource;
use Tests\Fixtures\Controllers\RouteLintController;
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
     * Test that a suppression entry with a non-array `rules` value is skipped
     * and the valid entry alongside it is still mapped
     * (kills LogicalAnd mutant #85: `isset($entry['rules']) || is_array(...)` vs `&&`).
     *
     * With `||` instead of `&&`, a missing `rules` key combined with `is_array`
     * being false would still satisfy the condition (because `isset` is true when
     * the key exists). Here we assert the final parsed RouteSuppression reflects
     * only the conforming rules array.
     *
     * @return void
     */
    public function testSuppressionWithMissingRulesKeyYieldsEmptyRulesOnSuppression(): void
    {
        $router = $this->getRouter();

        // Manually inject an action entry where 'rules' is absent
        $route = $router->get('billing', fn () => [])->name('billing.index');
        $route->setAction(array_merge($route->getAction(), [
            'api-toolkit::lint-ignore' => [
                ['reason' => 'No rules key present.'],
            ],
        ]));

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        $byName = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->name !== null) {
                $byName[$descriptor->name] = $descriptor;
            }
        }

        static::assertArrayHasKey('billing.index', $byName);

        $descriptor = $byName['billing.index'];

        // The missing-rules entry still produces a RouteSuppression with empty rules
        static::assertCount(1, $descriptor->suppressions);
        static::assertSame([], $descriptor->suppressions[0]->rules);
        static::assertSame('No rules key present.', $descriptor->suppressions[0]->reason);
    }

    /**
     * Test that a suppression entry where `rules` is not an array yields empty
     * rules on the RouteSuppression (kills LogicalAnd mutant #85 second angle).
     *
     * @return void
     */
    public function testSuppressionWithNonArrayRulesYieldsEmptyRulesOnSuppression(): void
    {
        $router = $this->getRouter();

        $route = $router->get('analytics', fn () => [])->name('analytics.index');
        $route->setAction(array_merge($route->getAction(), [
            'api-toolkit::lint-ignore' => [
                ['rules' => 'not-an-array', 'reason' => 'Non-array rules value.'],
            ],
        ]));

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        $byName = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->name !== null) {
                $byName[$descriptor->name] = $descriptor;
            }
        }

        static::assertArrayHasKey('analytics.index', $byName);

        $suppression = $byName['analytics.index']->suppressions[0];

        // Non-array rules must be treated as empty
        static::assertSame([], $suppression->rules);
    }

    /**
     * Test that the rules list on a RouteSuppression is re-indexed (kills
     * UnwrapArrayValues mutant #86: `array_values($entry['rules'])` vs raw value).
     *
     * Without `array_values`, an associative input array would be stored with
     * string keys instead of a 0-based list.
     *
     * @return void
     */
    public function testSuppressionRulesListIsReIndexed(): void
    {
        $router = $this->getRouter();

        $route = $router->get('reports', fn () => [])->name('reports.index');
        $route->setAction(array_merge($route->getAction(), [
            'api-toolkit::lint-ignore' => [
                ['rules' => ['foo' => 'R1', 'bar' => 'R3'], 'reason' => 'Re-index test.'],
            ],
        ]));

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        $byName = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->name !== null) {
                $byName[$descriptor->name] = $descriptor;
            }
        }

        static::assertArrayHasKey('reports.index', $byName);

        $rules = $byName['reports.index']->suppressions[0]->rules;

        // Must be a 0-based indexed list
        static::assertSame([0 => 'R1', 1 => 'R3'], $rules);
    }

    /**
     * Test that a suppression entry where `reason` is not a string defaults to
     * an empty string (kills LogicalAnd mutant #87:
     * `isset($entry['reason']) || is_string(...)` vs `&&`).
     *
     * With `||`, a non-string reason value would still satisfy the condition
     * (because isset is true when the key exists), causing a type error when
     * RouteSuppression stores it. With `&&` the fallback empty string is used.
     *
     * @return void
     */
    public function testSuppressionWithNonStringReasonDefaultsToEmptyString(): void
    {
        $router = $this->getRouter();

        $route = $router->get('exports', fn () => [])->name('exports.index');
        $route->setAction(array_merge($route->getAction(), [
            'api-toolkit::lint-ignore' => [
                ['rules' => ['R1'], 'reason' => 12345],
            ],
        ]));

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        $byName = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->name !== null) {
                $byName[$descriptor->name] = $descriptor;
            }
        }

        static::assertArrayHasKey('exports.index', $byName);

        $suppression = $byName['exports.index']->suppressions[0];

        // Non-string reason must fall back to empty string
        static::assertSame('', $suppression->reason);
    }

    /**
     * Test that two valid suppression entries both survive (kills ArrayOneItem
     * mutant #88: returns only the first suppression when count > 1).
     *
     * @return void
     */
    public function testMultipleSuppressionsAreAllMapped(): void
    {
        $router = $this->getRouter();

        $router->get('notifications', fn () => [])
            ->name('notifications.index')
            ->ignoreRouteLint(['R1'], 'First suppression reason.') // @phpstan-ignore method.notFound
            ->ignoreRouteLint(['R3'], 'Second suppression reason.') // @phpstan-ignore method.notFound
            ->ignoreRouteLint([], 'Third suppression, all rules.'); // @phpstan-ignore method.notFound

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        $byName = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->name !== null) {
                $byName[$descriptor->name] = $descriptor;
            }
        }

        static::assertArrayHasKey('notifications.index', $byName);

        $suppressions = $byName['notifications.index']->suppressions;

        static::assertCount(3, $suppressions);
        static::assertSame(['R1'], $suppressions[0]->rules);
        static::assertSame('First suppression reason.', $suppressions[0]->reason);
        static::assertSame(['R3'], $suppressions[1]->rules);
        static::assertSame('Second suppression reason.', $suppressions[1]->reason);
        static::assertSame([], $suppressions[2]->rules);
        static::assertSame('Third suppression, all rules.', $suppressions[2]->reason);
    }

    /**
     * Test that an app-owned controller route registered as a string action
     * appears in appRoutes() with isVendor === false.
     *
     * Kills the `is_string($uses) && isControllerVendor` → `||` mutant: under the
     * mutant a string controller action is evaluated with the short-circuit OR so the
     * vendor check fires even for non-string uses values, and conversely a string
     * action that resolves to an app-owned file may be wrongly excluded. Registering
     * a string controller action whose class file lives under tests/ (not vendor/)
     * and asserting it is present with isVendor false proves the AND guard is intact.
     *
     * @return void
     */
    public function testAppOwnedControllerRouteIsNotTreatedAsVendor(): void
    {
        $router = $this->getRouter();

        // Register using the string controller@method notation so `uses` is a string.
        // RouteLintController lives under tests/, so ReflectionClass resolves a
        // non-vendor file — the adapter must return it with isVendor === false.
        $router->get('widgets', [RouteLintController::class, 'index'])->name('widgets.index');

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        $byName = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->name !== null) {
                $byName[$descriptor->name] = $descriptor;
            }
        }

        static::assertArrayHasKey('widgets.index', $byName, 'App-owned controller route must appear in appRoutes().');
        static::assertFalse($byName['widgets.index']->isVendor, 'Controller whose file is under tests/ must not be flagged as vendor.');
    }

    /**
     * Test that a route without a string `uses` action value and without a
     * Closure is treated as app-owned (kills LogicalAnd mutant #89:
     * `is_string($uses) || $this->isControllerVendor($route)` vs `&&`).
     *
     * With `||`, a non-string `uses` value would still trigger isControllerVendor
     * (which could return true and incorrectly mark the route as vendor). The
     * `&&` guard ensures isControllerVendor is only called for string uses values.
     *
     * @return void
     */
    public function testRouteWithNonStringUsesActionIsNotTreatedAsVendor(): void
    {
        $router = $this->getRouter();

        // A route registered with a Closure is handled by the Closure branch
        // (not the string branch). Register a named closure route that we own —
        // this is the canonical non-string-uses scenario for app-owned routes.
        $router->get('health', fn () => ['status' => 'ok'])->name('health.check');

        $source      = new RouterRouteSource($router);
        $descriptors = $source->appRoutes();

        $names = array_map(static fn (RouteDescriptor $d) => $d->name, $descriptors);

        // The closure-backed app route must appear in the results
        static::assertContains('health.check', $names);

        // Confirm the returned descriptor is not flagged as vendor
        foreach ($descriptors as $descriptor) {
            if ($descriptor->name === 'health.check') {
                static::assertFalse($descriptor->isVendor);
            }
        }
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
