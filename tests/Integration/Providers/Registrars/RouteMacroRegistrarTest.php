<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\Providers\Registrars\RouteMacroRegistrar;
use Tests\TestCase;

/**
 * Integration tests for the RouteMacroRegistrar.
 *
 * The service provider registers the audience macros at boot; these tests prove
 * the macros are present, store their directives on the route action, merge
 * when chained, and are read back by the audience resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RouteMacroRegistrar::class)]
final class RouteMacroRegistrarTest extends TestCase
{
    /**
     * Test that the registrar registers the three audience macros.
     *
     * @return void
     */
    public function testRegistersMacros(): void
    {
        self::assertTrue(Route::hasMacro('undocumented'));
        self::assertTrue(Route::hasMacro('documentedIn'));
        self::assertTrue(Route::hasMacro('notDocumentedIn'));
    }

    /**
     * Test that the undocumented macro stores a blanket exclusion.
     *
     * @return void
     */
    public function testUndocumentedMacroStoresDirective(): void
    {
        $route = $this->makeRoute();

        self::assertSame($route, $route->undocumented()); // @phpstan-ignore method.notFound

        $stored = $route->getAction(AudienceResolver::ROUTE_ACTION_KEY);

        self::assertTrue($stored['undocumented']);
    }

    /**
     * Test that the documentedIn macro stores the named audiences.
     *
     * @return void
     */
    public function testDocumentedInMacroStoresAudiences(): void
    {
        $route = $this->makeRoute();
        $route->documentedIn('public', 'partner'); // @phpstan-ignore method.notFound

        $stored = $route->getAction(AudienceResolver::ROUTE_ACTION_KEY);

        self::assertSame(['public', 'partner'], $stored['documentedIn']);
    }

    /**
     * Test that the notDocumentedIn macro stores the named audiences.
     *
     * @return void
     */
    public function testNotDocumentedInMacroStoresAudiences(): void
    {
        $route = $this->makeRoute();
        $route->notDocumentedIn('internal'); // @phpstan-ignore method.notFound

        $stored = $route->getAction(AudienceResolver::ROUTE_ACTION_KEY);

        self::assertSame(['internal'], $stored['notDocumentedIn']);
    }

    /**
     * Test that successive macro calls merge their directives on the route.
     *
     * @return void
     */
    public function testSuccessiveMacrosMerge(): void
    {
        $route = $this->makeRoute();
        $route->documentedIn('public'); // @phpstan-ignore method.notFound
        $route->notDocumentedIn('internal'); // @phpstan-ignore method.notFound

        $stored = $route->getAction(AudienceResolver::ROUTE_ACTION_KEY);

        self::assertSame(['public'], $stored['documentedIn']);
        self::assertSame(['internal'], $stored['notDocumentedIn']);
        self::assertFalse($stored['undocumented']);
    }

    /**
     * Test that the stored directives are honoured by the audience resolver.
     *
     * @return void
     */
    public function testStoredDirectivesAreReadByResolver(): void
    {
        $route = $this->makeRoute();
        $route->notDocumentedIn('public'); // @phpstan-ignore method.notFound

        $resolver = new AudienceResolver;

        self::assertFalse($resolver->isInAudience(
            'public',
            AudienceResolver::POSTURE_BLOCKLIST,
            null,
            null,
            $route,
        ));
    }

    /**
     * Test that re-registering the macros is a safe no-op.
     *
     * @return void
     */
    public function testRegisterIsIdempotent(): void
    {
        (new RouteMacroRegistrar)->register();
        (new RouteMacroRegistrar)->register();

        self::assertTrue(Route::hasMacro('documentedIn'));
    }

    /**
     * Build a bare route instance.
     *
     * @return \Illuminate\Routing\Route
     */
    private function makeRoute(): Route
    {
        return new Route(['GET'], '/test', static fn (): null => null);
    }
}
