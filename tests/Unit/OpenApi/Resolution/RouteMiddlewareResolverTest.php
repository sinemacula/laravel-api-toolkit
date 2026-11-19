<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\EnsureActionIsAuthorized;
use SineMacula\ApiToolkit\Http\Middleware\RequireIfMatch;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequestsWithRedis;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;
use Tests\Fixtures\Controllers\CustomActionController;
use Tests\Fixtures\Middleware\EnforceQuota;
use Tests\Fixtures\Middleware\RequireSubscription;
use Tests\Fixtures\OpenApi\ClosureMiddlewareController;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathRouteFactsController;
use Tests\Fixtures\OpenApi\UnresolvableMiddlewareController;
use Tests\TestCase;

/**
 * Tests for the route middleware resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RouteMiddlewareResolver::class)]
final class RouteMiddlewareResolverTest extends TestCase
{
    /**
     * Test that a route with no middleware carries none of the facts.
     *
     * @return void
     */
    public function testRouteWithoutMiddlewareCarriesNoFacts(): void
    {
        $route    = $this->route();
        $resolver = $this->resolver();

        self::assertSame([], $resolver->guards($route));
        self::assertFalse($resolver->authenticates($route));
        self::assertFalse($resolver->authorizes($route));
        self::assertFalse($resolver->throttles($route));
        self::assertFalse($resolver->requiresIfMatch($route));
    }

    /**
     * Test that the auth alias resolves its guards in order, trimming blanks.
     *
     * @return void
     */
    public function testAuthAliasYieldsItsGuardsInOrder(): void
    {
        $route = $this->route('auth: api , ,admin');

        self::assertSame(['api', 'admin'], $this->resolver()->guards($route));
        self::assertTrue($this->resolver()->authenticates($route));
    }

    /**
     * Test that HTTP basic auth authenticates without contributing a guard.
     *
     * @return void
     */
    public function testBasicAuthAuthenticatesWithoutGuards(): void
    {
        $route = $this->route('auth.basic');

        self::assertTrue($this->resolver()->authenticates($route));
        self::assertSame([], $this->resolver()->guards($route));
    }

    /**
     * Test that everything after the first colon is the parameter list.
     *
     * @return void
     */
    public function testParametersKeepLaterColons(): void
    {
        self::assertSame(['scope:admin'], $this->resolver()->guards($this->route(Authenticate::class . ':scope:admin')));
    }

    /**
     * Test that guards accumulate across several Authenticate entries.
     *
     * @return void
     */
    public function testGuardsAccumulateAcrossEntries(): void
    {
        self::assertSame(['api', 'admin'], $this->resolver()->guards($this->route('auth:api', Authenticate::class . ':admin')));
    }

    /**
     * Test that an Authenticate entry without a guard resolves the default.
     *
     * @return void
     */
    public function testBareAuthResolvesConfiguredDefaultGuard(): void
    {
        $this->config()->set('auth.defaults.guard', 'sanctum');

        self::assertSame(['sanctum'], $this->resolver()->guards($this->route('auth')));
        self::assertSame(['sanctum'], $this->resolver()->guards($this->route('auth:')));
    }

    /**
     * Test that a missing or blank default guard falls back to web.
     *
     * @return void
     */
    public function testDefaultGuardFallsBackToWeb(): void
    {
        $this->config()->set('auth.defaults.guard', '');

        self::assertSame(['web'], $this->resolver()->guards($this->route('auth')));

        $this->config()->set('auth.defaults.guard', ['not-a-string']);

        self::assertSame(['web'], $this->resolver()->guards($this->route('auth')));
    }

    /**
     * Test that can and #[Authorize] authorize without authenticating.
     *
     * @return void
     */
    public function testAuthorizationChecksAreRecognised(): void
    {
        $gate      = $this->route('can:view,post');
        $attribute = new Route(['GET'], 'report', ['uses' => PathRouteFactsController::class . '@report']);

        self::assertTrue($this->resolver()->authorizes($gate));
        self::assertFalse($this->resolver()->authenticates($gate));
        self::assertTrue($this->resolver()->authorizes($this->bind($attribute)));
        self::assertTrue($this->resolver()->authorizes($this->route(Authorize::class . ':view-report')));
    }

    /**
     * Test that an authorized controller's resource gate is recognised.
     *
     * @return void
     */
    public function testAuthorizedControllerGateIsRecognised(): void
    {
        $route = new Route(['GET'], 'users', ['uses' => PathFixtureController::class . '@index']);

        self::assertTrue($this->resolver()->authorizes($this->bind($route)));
    }

    /**
     * Test that the throttle alias and throttle subclasses are recognised.
     *
     * @return void
     */
    public function testThrottlingIsRecognised(): void
    {
        self::assertTrue($this->resolver()->throttles($this->route('throttle:60,1')));
        self::assertTrue($this->resolver()->throttles($this->route(ThrottleRequests::class)));
        self::assertTrue($this->resolver()->throttles($this->route(ThrottleRequestsWithRedis::class . ':api')));
        self::assertFalse($this->resolver()->throttles($this->route('auth')));
    }

    /**
     * Test that requiring If-Match is recognised.
     *
     * @return void
     */
    public function testRequiringIfMatchIsRecognised(): void
    {
        self::assertTrue($this->resolver()->requiresIfMatch($this->route(RequireIfMatch::class)));
        self::assertFalse($this->resolver()->requiresIfMatch($this->route('throttle:60,1')));
    }

    /**
     * Test that a middleware group expands into the facts of its members.
     *
     * @return void
     */
    public function testMiddlewareGroupIsExpanded(): void
    {
        $this->router()->middlewareGroup('secured', ['auth:api', 'throttle:api']);

        $route = $this->route('secured');

        self::assertSame(['api'], $this->resolver()->guards($route));
        self::assertTrue($this->resolver()->throttles($route));
    }

    /**
     * Test that a middleware removed with withoutMiddleware() is not a fact.
     *
     * @return void
     */
    public function testExcludedMiddlewareIsIgnored(): void
    {
        $this->router()->middlewareGroup('secured', ['auth:api', 'throttle:api']);

        $route = $this->route('secured')->withoutMiddleware('throttle');

        self::assertFalse($this->resolver()->throttles($route));
        self::assertTrue($this->resolver()->authenticates($route));
    }

    /**
     * Test that an unknown alias is ignored rather than mistaken for a fact.
     *
     * @return void
     */
    public function testUnknownAliasIsIgnored(): void
    {
        $route = $this->route('unknown-alias:auth');

        self::assertSame([], $this->resolver()->guards($route));
        self::assertFalse($this->resolver()->authorizes($route));
    }

    /**
     * Test that a closure middleware is skipped, string entries still read.
     *
     * @return void
     */
    public function testClosureMiddlewareIsSkipped(): void
    {
        $route = new Route(['GET'], 'test', ['uses' => ClosureMiddlewareController::class . '@show']);

        self::assertSame(['api'], $this->resolver()->guards($this->bind($route)));
    }

    /**
     * Test that unresolvable controller middleware degrades to no facts.
     *
     * @return void
     */
    public function testUnresolvableControllerMiddlewareDegradesToNone(): void
    {
        $route = (new Route(['GET'], 'test', ['uses' => UnresolvableMiddlewareController::class . '@show']))->middleware('auth:api');

        self::assertSame([], $this->resolver()->guards($this->bind($route)));
        self::assertFalse($this->resolver()->authenticates($route));
    }

    /**
     * Test that aliased, grouped and parameterised middleware are listed by
     * class, once each, in priority order.
     *
     * @return void
     */
    public function testClassesListsEachMiddlewareClassOnceInPriorityOrder(): void
    {
        $this->router()->aliasMiddleware('subscribed', RequireSubscription::class);
        $this->router()->middlewareGroup('billing', ['subscribed:pro', EnforceQuota::class]);

        $route = $this->route('billing', 'can:view,post', 'subscribed:team', 'auth:api');

        self::assertSame(
            [RequireSubscription::class, EnforceQuota::class, Authenticate::class, Authorize::class],
            $this->resolver()->classes($route),
        );
    }

    /**
     * Test that closures and unknown aliases are not listed as classes.
     *
     * @return void
     */
    public function testClassesSkipsClosuresAndUnknownAliases(): void
    {
        $route = new Route(['GET'], 'test', ['uses' => ClosureMiddlewareController::class . '@show']);

        self::assertSame([Authenticate::class], $this->resolver()->classes($this->bind($route)));
        self::assertSame([EnforceQuota::class], $this->resolver()->classes($this->route('unknown-alias:quota', EnforceQuota::class)));
    }

    /**
     * Test that middleware removed by alias, by class or by parent class is not
     * listed.
     *
     * @return void
     */
    public function testClassesHonoursExcludedMiddleware(): void
    {
        $this->router()->aliasMiddleware('subscribed', RequireSubscription::class);

        $byAlias  = $this->route('subscribed', EnforceQuota::class)->withoutMiddleware('subscribed');
        $byClass  = $this->route('subscribed:pro', EnforceQuota::class)->withoutMiddleware(RequireSubscription::class);
        $byParent = $this->route(ThrottleRequestsWithRedis::class . ':api', EnforceQuota::class)->withoutMiddleware(ThrottleRequests::class);

        self::assertSame([EnforceQuota::class], $this->resolver()->classes($byAlias));
        self::assertSame([EnforceQuota::class], $this->resolver()->classes($byClass));
        self::assertSame([EnforceQuota::class], $this->resolver()->classes($byParent));
    }

    /**
     * Test that controller middleware is listed only for the actions it applies
     * to.
     *
     * @return void
     */
    public function testClassesIncludesControllerMiddlewareForItsActions(): void
    {
        $update = new Route(['PUT'], 'users/{user}', ['uses' => CustomActionController::class . '@update']);
        $lock   = new Route(['POST'], 'users/{user}/lock', ['uses' => CustomActionController::class . '@lock']);

        self::assertSame([Authorize::class, EnsureActionIsAuthorized::class], $this->resolver()->classes($this->bind($update)));
        self::assertSame([], $this->resolver()->classes($this->bind($lock)));
    }

    /**
     * Build a resolver backed by the application router.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver
     */
    private function resolver(): RouteMiddlewareResolver
    {
        return new RouteMiddlewareResolver($this->router());
    }

    /**
     * Build a closure route carrying the given middleware.
     *
     * @param  string  ...$middleware
     * @return \Illuminate\Routing\Route
     */
    private function route(string ...$middleware): Route
    {
        $route = $this->bind(new Route(['GET'], 'test', static fn (): null => null));

        return $middleware === [] ? $route : $route->middleware($middleware);
    }

    /**
     * Bind the route to the application router and container.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return \Illuminate\Routing\Route
     */
    private function bind(Route $route): Route
    {
        assert($this->app !== null);

        return $route->setRouter($this->router())->setContainer($this->app);
    }

    /**
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        assert($this->app !== null);

        return $this->app->make(Router::class);
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function config(): ConfigRepository
    {
        assert($this->app !== null);

        /** @var \Illuminate\Config\Repository */
        return $this->app->make('config');
    }
}
