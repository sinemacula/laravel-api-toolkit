<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\EnsureActionIsAuthorized;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;
use Tests\Fixtures\Controllers\CustomActionController;
use Tests\Fixtures\OpenApi\PathRouteFactsController;
use Tests\TestCase;

/**
 * Tests for the EnsureActionIsAuthorized middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EnsureActionIsAuthorized::class)]
final class EnsureActionIsAuthorizedTest extends TestCase
{
    /** The refusal's message after the action name */
    private const string REFUSAL = ' action declares no authorization: gate it with #[Authorize], or mark it #[CustomAuthorization] or #[SkipAuthorization]';

    /**
     * Test that a can: alias on the route satisfies the guard.
     *
     * @return void
     */
    public function testCanAliasSatisfiesTheGuard(): void
    {
        self::assertSame('passed', $this->handle($this->router()->get('aliased', static fn (): null => null)->middleware('can:view-report')));
    }

    /**
     * Test that an #[Authorize] attribute on the action satisfies the guard.
     *
     * @return void
     */
    public function testAuthorizeAttributeSatisfiesTheGuard(): void
    {
        self::assertSame('passed', $this->handle($this->router()->get('report', [PathRouteFactsController::class, 'report'])));
    }

    /**
     * Test that a route's can() call satisfies the guard.
     *
     * @return void
     */
    public function testRouteCanSatisfiesTheGuard(): void
    {
        self::assertSame('passed', $this->handle($this->router()->get('fluent', static fn (): null => null)->can('view-report')));
    }

    /**
     * Test that a can: entry in a middleware group satisfies the guard.
     *
     * @return void
     */
    public function testGroupCanSatisfiesTheGuard(): void
    {
        $this->router()->middlewareGroup('gated', ['can:view-report']);

        self::assertSame('passed', $this->handle($this->router()->get('grouped', static fn (): null => null)->middleware('gated')));
    }

    /**
     * Test that an action declaring no authorization is refused, naming its
     * controller and method.
     *
     * @return void
     */
    public function testActionWithoutAuthorizationIsRefused(): void
    {
        $route = $this->router()->post('users/{user}/publish', [CustomActionController::class, 'publish']);

        self::assertSame('The [' . CustomActionController::class . '@publish]' . self::REFUSAL, $this->refusal($route));
    }

    /**
     * Test that a route carrying only the guard is refused, since the guard is
     * not itself an authorization check.
     *
     * @return void
     */
    public function testRouteCarryingOnlyTheGuardIsRefused(): void
    {
        $route = $this->router()->get('guarded', static fn (): null => null)->middleware(EnsureActionIsAuthorized::class);

        self::assertSame('The [Closure]' . self::REFUSAL, $this->refusal($route));
    }

    /**
     * Test that a request no route has matched is refused.
     *
     * @return void
     */
    public function testUnroutedRequestIsRefused(): void
    {
        self::assertSame('The [unrouted]' . self::REFUSAL, $this->refusal(null));
    }

    /**
     * Run the guard over a request matched to the given route.
     *
     * @param  \Illuminate\Routing\Route|null  $route
     * @return string
     *
     * @throws \LogicException
     */
    private function handle(?Route $route): string
    {
        $request = Request::create('/');
        $request->setRouteResolver(static fn (): ?Route => $route);

        $response = (new EnsureActionIsAuthorized(new RouteMiddlewareResolver($this->router())))
            ->handle($request, static fn (): Response => new Response('passed'));

        return (string) $response->getContent();
    }

    /**
     * Run the guard over a request matched to the given route and return the
     * message it refuses with.
     *
     * @param  \Illuminate\Routing\Route|null  $route
     * @return string
     */
    private function refusal(?Route $route): string
    {
        try {
            $this->handle($route);
        } catch (\LogicException $exception) {
            return $exception->getMessage();
        }

        self::fail('The guard did not refuse the request.');
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
}
