<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Security;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeMapper;
use SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver;
use Tests\TestCase;

/**
 * Tests for the security scheme resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SecuritySchemeResolver::class)]
final class SecuritySchemeResolverTest extends TestCase
{
    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGuards();
    }

    /**
     * Test that a route with no auth middleware is public.
     *
     * @return void
     */
    public function testRouteWithoutAuthMiddlewareIsPublic(): void
    {
        self::assertSame([], $this->resolver()->securityFor($this->route()));
    }

    /**
     * Test that a route carrying only authorization middleware is public, as
     * authorization is not authentication.
     *
     * @return void
     */
    public function testCanOnlyRouteIsPublic(): void
    {
        self::assertSame([], $this->resolver()->securityFor($this->route('can:view,App\Post')));
    }

    /**
     * Test that a single-guard route yields the guard driver's scheme as one
     * requirement.
     *
     * @return void
     */
    public function testSingleGuardYieldsOneRequirement(): void
    {
        self::assertSame([['bearerAuth' => []]], $this->resolver()->securityFor($this->route('auth:api')));
    }

    /**
     * Test that guards mapping to different schemes yield separate
     * requirements, expressing the OR of the two schemes.
     *
     * @return void
     */
    public function testDifferentSchemesYieldSeparateRequirements(): void
    {
        self::assertSame(
            [['bearerAuth' => []], ['basicAuth' => []]],
            $this->resolver()->securityFor($this->route('auth:api,admin')),
        );
    }

    /**
     * Test that guards mapping to the same scheme collapse to one requirement,
     * never revealing the second guard.
     *
     * @return void
     */
    public function testSameSchemeGuardsDedupe(): void
    {
        self::assertSame([['bearerAuth' => []]], $this->resolver()->securityFor($this->route('auth:api,sanctum')));
    }

    /**
     * Test that a bare auth middleware resolves the application's default
     * guard.
     *
     * @return void
     */
    public function testBareAuthResolvesDefaultGuard(): void
    {
        self::assertSame([['cookieAuth' => []]], $this->resolver()->securityFor($this->route('auth')));
    }

    /**
     * Test that a guard absent from config is skipped rather than throwing.
     *
     * @return void
     */
    public function testUnconfiguredGuardIsSkipped(): void
    {
        self::assertSame([], $this->resolver()->securityFor($this->route('auth:ghost')));
    }

    /**
     * Test that an unconfigured guard is dropped while its configured siblings
     * still resolve.
     *
     * @return void
     */
    public function testUnconfiguredGuardIsDroppedFromAMixedList(): void
    {
        self::assertSame([['bearerAuth' => []]], $this->resolver()->securityFor($this->route('auth:api,ghost')));
    }

    /**
     * Test that a guard whose driver has no scheme mapping is skipped.
     *
     * @return void
     */
    public function testGuardWithUnmappedDriverIsSkipped(): void
    {
        $this->config()->set('auth.guards.custom', ['driver' => 'carrier-pigeon']);

        self::assertSame([], $this->resolver()->securityFor($this->route('auth:custom')));
    }

    /**
     * Test that a skipped guard does not halt the loop: guards after both a
     * deduped scheme and an unconfigured guard still resolve.
     *
     * @return void
     */
    public function testSkippedGuardDoesNotHaltLaterGuards(): void
    {
        self::assertSame(
            [['bearerAuth' => []], ['basicAuth' => []]],
            $this->resolver()->securityFor($this->route('auth:api,sanctum,admin')),
        );

        self::assertSame([['bearerAuth' => []]], $this->resolver()->securityFor($this->route('auth:ghost,api')));
    }

    /**
     * Test that guards accumulate across multiple separate auth middleware
     * entries rather than only the last one surviving.
     *
     * @return void
     */
    public function testGuardsAccumulateAcrossMultipleAuthMiddleware(): void
    {
        self::assertSame(
            [['bearerAuth' => []], ['basicAuth' => []]],
            $this->resolver()->securityFor($this->route('auth:api', 'auth:admin')),
        );
    }

    /**
     * Test that whitespace around a guard name is trimmed so a spaced sibling
     * still resolves to its scheme.
     *
     * @return void
     */
    public function testGuardWhitespaceIsTrimmed(): void
    {
        self::assertSame(
            [['bearerAuth' => []], ['basicAuth' => []]],
            $this->resolver()->securityFor($this->route('auth:api, admin')),
        );
    }

    /**
     * Test that a non-auth middleware is ignored even when its tail after the
     * prefix spells a configured guard name.
     *
     * @return void
     */
    public function testNonAuthMiddlewareWithGuardLikeTailIsPublic(): void
    {
        self::assertSame([], $this->resolver()->securityFor($this->route('role:api')));
    }

    /**
     * Test that a guard whose configured driver is not a string is skipped
     * rather than resolved.
     *
     * @return void
     */
    public function testNonStringGuardDriverIsSkipped(): void
    {
        $this->config()->set('auth.guards.weird', ['driver' => 123]);

        self::assertSame([], $this->resolver()->securityFor($this->route('auth:weird')));
    }

    /**
     * Test that a configured default guard other than the fallback drives the
     * bare auth middleware's scheme.
     *
     * @return void
     */
    public function testConfiguredDefaultGuardDrivesBareAuth(): void
    {
        $this->config()->set('auth.defaults.guard', 'api');

        self::assertSame([['bearerAuth' => []]], $this->resolver()->securityFor($this->route('auth')));
    }

    /**
     * Test that a non-string configured default guard falls back to the web
     * guard for the bare auth middleware.
     *
     * @return void
     */
    public function testNonStringDefaultGuardFallsBackToWeb(): void
    {
        $this->config()->set('auth.defaults.guard', 123);

        self::assertSame([['cookieAuth' => []]], $this->resolver()->securityFor($this->route('auth')));
    }

    /**
     * Test that definitionFor delegates to the mapper for a referenced scheme.
     *
     * @return void
     */
    public function testDefinitionForDelegatesToMapper(): void
    {
        self::assertSame(
            ['type' => 'http', 'scheme' => 'basic'],
            $this->resolver()->definitionFor('basicAuth'),
        );
    }

    /**
     * Build a resolver backed by a real mapper.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeResolver
     */
    private function resolver(): SecuritySchemeResolver
    {
        return new SecuritySchemeResolver(new SecuritySchemeMapper);
    }

    /**
     * Build a closure route carrying the given middleware.
     *
     * @param  string  ...$middleware
     * @return \Illuminate\Routing\Route
     */
    private function route(string ...$middleware): Route
    {
        $route = new Route(['GET'], 'test', static fn (): null => null);

        return $middleware === [] ? $route : $route->middleware($middleware);
    }

    /**
     * Configure the auth guards the resolver reads drivers from.
     *
     * @return void
     */
    private function configureGuards(): void
    {
        $config = $this->config();

        $config->set('auth.defaults.guard', 'web');
        $config->set('auth.guards.api', ['driver' => 'jwt']);
        $config->set('auth.guards.web', ['driver' => 'session']);
        $config->set('auth.guards.sanctum', ['driver' => 'sanctum']);
        $config->set('auth.guards.admin', ['driver' => 'basic']);
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function config(): ConfigRepository
    {
        assert($this->app !== null);

        /** @var \Illuminate\Contracts\Config\Repository */
        return $this->app->make('config');
    }
}
