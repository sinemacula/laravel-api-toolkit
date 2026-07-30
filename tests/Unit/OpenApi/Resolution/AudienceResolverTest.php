<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Fixtures\OpenApi\AudienceFixtureController;
use Tests\Fixtures\OpenApi\NotDocumentedInPublicController;
use Tests\Fixtures\OpenApi\OnlyInternalController;
use Tests\Fixtures\OpenApi\UndocumentedController;

/**
 * Tests for the audience resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AudienceResolver::class)]
final class AudienceResolverTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\OpenApi\Resolution\AudienceResolver */
    private AudienceResolver $resolver;

    /**
     * Set up the resolver under test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new AudienceResolver;
    }

    /**
     * Test that a blocklist audience documents a route with no directives.
     *
     * @return void
     */
    public function testBlocklistDocumentsEverythingByDefault(): void
    {
        self::assertTrue($this->resolver->isInAudience(
            'public',
            QuerySurface::POSTURE_BLOCKLIST,
            AudienceFixtureController::class,
            'none',
            $this->bareRoute(),
        ));
    }

    /**
     * Test that a class-level exclusion removes the route from the audience.
     *
     * @return void
     */
    public function testClassLevelExclusionRemovesFromAudience(): void
    {
        self::assertFalse($this->resolver->isInAudience(
            'public',
            QuerySurface::POSTURE_BLOCKLIST,
            NotDocumentedInPublicController::class,
            'none',
            $this->bareRoute(),
        ));
    }

    /**
     * Test that an allowlist audience excludes an undeclared route.
     *
     * @return void
     */
    public function testAllowlistExcludesByDefault(): void
    {
        self::assertFalse($this->resolver->isInAudience(
            'partner',
            QuerySurface::POSTURE_ALLOWLIST,
            AudienceFixtureController::class,
            'none',
            $this->bareRoute(),
        ));
    }

    /**
     * Test that a method-level inclusion opts a route into an allowlist.
     *
     * @return void
     */
    public function testMethodInclusionOptsIntoAllowlist(): void
    {
        self::assertTrue($this->resolver->isInAudience(
            'partner',
            QuerySurface::POSTURE_ALLOWLIST,
            AudienceFixtureController::class,
            'documentedInPartner',
            $this->bareRoute(),
        ));
    }

    /**
     * Test that a blanket Undocumented removes the route from every audience.
     *
     * @return void
     */
    public function testUndocumentedRemovesFromAllAudiences(): void
    {
        self::assertFalse($this->resolver->isInAudience(
            'public',
            QuerySurface::POSTURE_BLOCKLIST,
            UndocumentedController::class,
            'none',
            $this->bareRoute(),
        ));

        self::assertFalse($this->resolver->isInAudience(
            'internal',
            QuerySurface::POSTURE_BLOCKLIST,
            UndocumentedController::class,
            'none',
            $this->bareRoute(),
        ));
    }

    /**
     * Test that Undocumented plus DocumentedIn documents only the named
     * audience and excludes the route from all others.
     *
     * @return void
     */
    public function testOnlyInNamedAudienceViaUndocumentedPlusInclusion(): void
    {
        self::assertTrue($this->resolver->isInAudience(
            'internal',
            QuerySurface::POSTURE_BLOCKLIST,
            OnlyInternalController::class,
            'none',
            $this->bareRoute(),
        ));

        self::assertFalse($this->resolver->isInAudience(
            'public',
            QuerySurface::POSTURE_BLOCKLIST,
            OnlyInternalController::class,
            'none',
            $this->bareRoute(),
        ));
    }

    /**
     * Test that a method directive overrides the class directive.
     *
     * @return void
     */
    public function testMethodOverridesClass(): void
    {
        self::assertTrue($this->resolver->isInAudience(
            'public',
            QuerySurface::POSTURE_BLOCKLIST,
            NotDocumentedInPublicController::class,
            'documentedInPublic',
            $this->bareRoute(),
        ));
    }

    /**
     * Test that a route macro directive overrides the method directive.
     *
     * @return void
     */
    public function testRouteMacroOverridesMethod(): void
    {
        $route = $this->routeWithDirectives(['notDocumentedIn' => ['public']]);

        self::assertFalse($this->resolver->isInAudience(
            'public',
            QuerySurface::POSTURE_BLOCKLIST,
            AudienceFixtureController::class,
            'documentedInPublic',
            $route,
        ));
    }

    /**
     * Test that an inclusion and exclusion at the same level exclude the route.
     *
     * @return void
     */
    public function testSameLevelExclusionWins(): void
    {
        self::assertFalse($this->resolver->isInAudience(
            'partner',
            QuerySurface::POSTURE_BLOCKLIST,
            AudienceFixtureController::class,
            'conflict',
            $this->bareRoute(),
        ));
    }

    /**
     * Test that resolution falls back to the posture when no controller or
     * directives are available.
     *
     * @return void
     */
    public function testFallsBackToPostureWithoutController(): void
    {
        self::assertTrue($this->resolver->isInAudience(
            'public',
            QuerySurface::POSTURE_BLOCKLIST,
            null,
            null,
            $this->bareRoute(),
        ));

        self::assertFalse($this->resolver->isInAudience(
            'partner',
            QuerySurface::POSTURE_ALLOWLIST,
            null,
            null,
            $this->bareRoute(),
        ));
    }

    /**
     * Test that a route-level inclusion opts into an allowlist audience.
     *
     * @return void
     */
    public function testRouteLevelInclusionOptsIntoAllowlist(): void
    {
        $route = $this->routeWithDirectives(['documentedIn' => ['partner']]);

        self::assertTrue($this->resolver->isInAudience(
            'partner',
            QuerySurface::POSTURE_ALLOWLIST,
            null,
            null,
            $route,
        ));
    }

    /**
     * Test that a route-level Undocumented overrides a method inclusion.
     *
     * @return void
     */
    public function testRouteLevelUndocumentedOverridesMethodInclusion(): void
    {
        $route = $this->routeWithDirectives(['undocumented' => true]);

        self::assertFalse($this->resolver->isInAudience(
            'public',
            QuerySurface::POSTURE_BLOCKLIST,
            AudienceFixtureController::class,
            'documentedInPublic',
            $route,
        ));
    }

    /**
     * Test that an unknown method on the controller is treated as having no
     * directives.
     *
     * @return void
     */
    public function testUnknownMethodFallsBackToClassAndPosture(): void
    {
        self::assertTrue($this->resolver->isInAudience(
            'public',
            QuerySurface::POSTURE_BLOCKLIST,
            AudienceFixtureController::class,
            'missingMethod',
            $this->bareRoute(),
        ));
    }

    /**
     * Build a route carrying no audience directives.
     *
     * @return \Illuminate\Routing\Route
     */
    private function bareRoute(): Route
    {
        return new Route(['GET'], '/test', static fn (): null => null);
    }

    /**
     * Build a route carrying the given audience directives.
     *
     * @param  array<string, mixed>  $directives
     * @return \Illuminate\Routing\Route
     */
    private function routeWithDirectives(array $directives): Route
    {
        $route  = $this->bareRoute();
        $action = $route->getAction();

        $action[AudienceResolver::ROUTE_ACTION_KEY] = $directives;

        $route->setAction($action);

        return $route;
    }
}
