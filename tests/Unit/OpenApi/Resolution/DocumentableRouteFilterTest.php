<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Http\Request;
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteFileRegistrar;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\SerializableClosure\SerializableClosure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;
use Tests\Fixtures\OpenApi\RouteFileServiceProvider;
use Tests\TestCase;

/**
 * Tests for the documentable route filter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DocumentableRouteFilter::class)]
final class DocumentableRouteFilterTest extends TestCase
{
    /** @var string The application route file declaring a closure route. */
    private const string ROUTE_FILE = __DIR__ . '/../../../Fixtures/OpenApi/Routes/closures.inc';

    /** @var \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter */
    private DocumentableRouteFilter $filter;

    /**
     * Set up the filter under test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new DocumentableRouteFilter;
    }

    /**
     * Test that a controller under a default-blocklisted prefix is excluded.
     *
     * @return void
     */
    public function testClassUnderBlocklistedPrefixIsExcluded(): void
    {
        self::assertTrue($this->filter->isExcluded(RedirectController::class, $this->route()));
    }

    /**
     * Test that an ordinary application controller is kept.
     *
     * @return void
     */
    public function testApplicationClassIsKept(): void
    {
        self::assertFalse($this->filter->isExcluded('App\Http\Controllers\UserController', $this->route()));
    }

    /**
     * Test that a null controller with an unmatchable closure handler is kept.
     *
     * @return void
     */
    public function testNullControllerWithUnmatchableClosureIsKept(): void
    {
        self::assertFalse($this->filter->isExcluded(null, $this->route(static fn (): null => null)));
    }

    /**
     * Test that a config override replaces the built-in default prefixes.
     *
     * @return void
     */
    public function testConfigOverrideReplacesDefault(): void
    {
        $this->setBlocklist(['Acme\Internal']);

        self::assertTrue($this->filter->isExcluded('Acme\Internal\Reports\Controller', $this->route()));
        self::assertFalse($this->filter->isExcluded(RedirectController::class, $this->route()));
    }

    /**
     * Test that a prefix matches only on a namespace boundary.
     *
     * @return void
     */
    public function testPrefixMatchesOnNamespaceBoundary(): void
    {
        self::assertFalse($this->filter->isExcluded('IlluminateApp\Http\Controller', $this->route()));
    }

    /**
     * Test that a prefix without a trailing separator matches its boundary.
     *
     * @return void
     */
    public function testPrefixWithoutSeparatorMatchesOnBoundary(): void
    {
        self::assertTrue($this->filter->isExcluded('Laravel\Horizon\Http\Controllers\HomeController', $this->route()));
        self::assertFalse($this->filter->isExcluded('Laravel\HorizonExtra\Controller', $this->route()));
    }

    /**
     * Test that a closure whose declaring namespace is blocklisted is excluded.
     *
     * @return void
     */
    public function testClosureUnderBlocklistedNamespaceIsExcluded(): void
    {
        $this->setBlocklist([__NAMESPACE__]);

        self::assertTrue($this->filter->isExcluded(null, $this->route(static fn (): null => null)));
    }

    /**
     * Test that a closure declared in a route file the router loads is kept
     * under the default blocklist, though the router's loader scopes it.
     *
     * @return void
     */
    public function testClosureFromRouterLoadedRouteFileIsKept(): void
    {
        $this->router()->group([], self::ROUTE_FILE);

        $route = $this->loadedRoute();

        self::assertSame(RouteFileRegistrar::class, $this->scopeOf($route));
        self::assertFalse($this->filter->isExcluded(null, $route));
    }

    /**
     * Test that a closure declared in a route file a service provider loads is
     * kept under the default blocklist, though the provider base scopes it.
     *
     * @return void
     */
    public function testClosureFromProviderLoadedRouteFileIsKept(): void
    {
        assert($this->app !== null);

        (new RouteFileServiceProvider($this->app))->boot();

        $route = $this->loadedRoute();

        self::assertSame(ServiceProvider::class, $this->scopeOf($route));
        self::assertFalse($this->filter->isExcluded(null, $route));
    }

    /**
     * Test that a closure a cached route table serialized is still excluded by
     * its declaring namespace.
     *
     * @return void
     */
    public function testCachedClosureUnderBlocklistedNamespaceIsExcluded(): void
    {
        $this->setBlocklist([__NAMESPACE__]);

        $route = $this->route(static fn (): null => null);
        $route->prepareForSerialization();

        self::assertIsString($route->getAction('uses'));
        self::assertTrue($this->filter->isExcluded(null, $route));
    }

    /**
     * Test that a signed serialized closure is restored like an unsigned one.
     *
     * @return void
     */
    public function testSignedSerializedClosureUnderBlocklistedNamespaceIsExcluded(): void
    {
        $this->setBlocklist([__NAMESPACE__]);

        $handler = serialize(new SerializableClosure(static fn (): null => null));

        self::assertTrue($this->filter->isExcluded(null, $this->route($handler)));
    }

    /**
     * Test that a non-closure handler without a controller is kept.
     *
     * @return void
     */
    public function testMalformedHandlerNeverThrows(): void
    {
        self::assertFalse($this->filter->isExcluded(null, $this->route('SomeController@index')));
    }

    /**
     * Test that an empty configured blocklist documents everything.
     *
     * @return void
     */
    public function testEmptyBlocklistKeepsEverything(): void
    {
        $this->setBlocklist([]);

        self::assertFalse($this->filter->isExcluded(RedirectController::class, $this->route()));
    }

    /**
     * Test that a string function-name handler is never reflected.
     *
     * @return void
     */
    public function testNonClosureStringHandlerIsNeverReflected(): void
    {
        require_once __DIR__ . '/../../../Fixtures/OpenApi/RouteHandlerStubs.php';

        $this->setBlocklist(['Tests\Fixtures\OpenApi\RouteHandlers']);

        $handler = 'Tests\Fixtures\OpenApi\RouteHandlers\stub';

        self::assertFalse($this->filter->isExcluded(null, $this->route($handler)));
    }

    /**
     * Test that a lone namespace separator entry matches no class.
     *
     * @return void
     */
    public function testSeparatorOnlyPrefixExcludesNothing(): void
    {
        $this->setBlocklist(['\\']);

        self::assertFalse($this->filter->isExcluded('\Acme\Internal\Reports\Controller', $this->route()));
        self::assertFalse($this->filter->isExcluded('Acme\Internal\Reports\Controller', $this->route()));
    }

    /**
     * Provide malformed blocklists, with the message each fails with.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function malformedBlocklistProvider(): iterable
    {
        yield 'a single prefix' => ['Acme\Internal', 'The api-toolkit.openapi.exclude.namespaces setting must be a list of non-empty strings; [\'Acme\Internal\'] given.'];
        yield 'an empty prefix' => [['Acme\Internal', ''], 'The api-toolkit.openapi.exclude.namespaces.1 setting must be a non-empty string; [\'\'] given.'];
        yield 'a non-string prefix' => [[new \ArrayObject], 'The api-toolkit.openapi.exclude.namespaces.0 setting must be a non-empty string; [ArrayObject] given.'];
    }

    /**
     * Test that a malformed blocklist fails rather than falling back to the
     * built-in default or skipping entries.
     *
     * @param  mixed  $blocklist
     * @param  string  $message
     * @return void
     */
    #[DataProvider('malformedBlocklistProvider')]
    public function testMalformedBlocklistFails(mixed $blocklist, string $message): void
    {
        Config::set('api-toolkit.openapi.exclude.namespaces', $blocklist);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->filter->isExcluded(RedirectController::class, $this->route());
    }

    /**
     * Build a route carrying the given handler, defaulting to a no-op closure.
     *
     * @param  (\Closure(): mixed)|string|null  $handler
     * @return \Illuminate\Routing\Route
     */
    private function route(\Closure|string|null $handler = null): Route
    {
        $route = new Route(['GET'], '/test', $handler instanceof \Closure ? $handler : static fn (): null => null);

        // The router stores a callable string as the action's handler verbatim.
        return is_string($handler) ? $route->setAction(['uses' => $handler]) : $route;
    }

    /**
     * Resolve the closure route the route file declares.
     *
     * @return \Illuminate\Routing\Route
     */
    private function loadedRoute(): Route
    {
        return $this->router()->getRoutes()->match(Request::create('/status'));
    }

    /**
     * Resolve the scope class of the route's closure handler.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return string|null
     */
    private function scopeOf(Route $route): ?string
    {
        $handler = $route->getAction('uses');

        assert($handler instanceof \Closure);

        return (new \ReflectionFunction($handler))->getClosureScopeClass()?->getName();
    }

    /**
     * Resolve the application router.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        assert($this->app !== null);

        return $this->app->make(Router::class);
    }

    /**
     * Override the configured exclusion blocklist.
     *
     * @param  list<string>  $prefixes
     * @return void
     */
    private function setBlocklist(array $prefixes): void
    {
        Config::set('api-toolkit.openapi.exclude.namespaces', $prefixes);
    }
}
