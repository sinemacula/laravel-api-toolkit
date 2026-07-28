<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentableRouteFilter;
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
     * Test that a config override adds a prefix and replaces the built-in
     * default rather than merging with it.
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
     * Test that a prefix matches only on a namespace boundary, so 'Illuminate\'
     * never excludes a class merely sharing the leading characters.
     *
     * @return void
     */
    public function testPrefixMatchesOnNamespaceBoundary(): void
    {
        self::assertFalse($this->filter->isExcluded('IlluminateApp\Http\Controller', $this->route()));
    }

    /**
     * Test that a prefix without a trailing separator still matches on its
     * boundary rather than as a bare string prefix.
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
     * Test that a non-closure handler with no controller degrades to keeping
     * the route without throwing.
     *
     * @return void
     */
    public function testMalformedHandlerNeverThrows(): void
    {
        self::assertFalse($this->filter->isExcluded(null, $this->route('SomeController@index')));
    }

    /**
     * Test that an empty configured blocklist documents everything, including a
     * class that the built-in default would have excluded.
     *
     * @return void
     */
    public function testEmptyBlocklistKeepsEverything(): void
    {
        $this->setBlocklist([]);

        self::assertFalse($this->filter->isExcluded(RedirectController::class, $this->route()));
    }

    /**
     * Build a route carrying the given handler, defaulting to a no-op closure.
     *
     * @param  (\Closure(): mixed)|string|null  $handler
     * @return \Illuminate\Routing\Route
     */
    private function route(\Closure|string|null $handler = null): Route
    {
        return new Route(['GET'], '/test', $handler ?? static fn (): null => null);
    }

    /**
     * Override the configured exclusion blocklist.
     *
     * @param  list<string>  $prefixes
     * @return void
     */
    private function setBlocklist(array $prefixes): void
    {
        assert($this->app !== null);

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->app->make('config');

        $config->set('api-toolkit.openapi.exclude.namespaces', $prefixes);
    }
}
