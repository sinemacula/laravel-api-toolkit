<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;

/**
 * Reads the authentication, authorization, and throttling facts of a route.
 *
 * The route's middleware is resolved the way the router dispatches it: groups
 * and aliases expand to classes and withoutMiddleware() exclusions apply, so a
 * guard or limit applied through a group is seen and a removed one is not. Each
 * resolved entry is classified by class: Laravel's Authenticate (an auth
 * guard), Authorize (a `can:` check or an #[Authorize] attribute), and
 * ThrottleRequests (a rate limit), subclasses included. Anything else - an
 * unknown alias, a closure, an unrelated class - is ignored, and a controller
 * whose middleware cannot be resolved degrades to none rather than aborting the
 * export.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RouteMiddlewareResolver
{
    /** The default guard assumed when none is configured */
    private const string DEFAULT_GUARD_FALLBACK = 'web';

    /**
     * Constructor.
     *
     * @param  \Illuminate\Routing\Router  $router
     */
    public function __construct(

        /** The router resolving middleware aliases, groups, and exclusions. */
        private Router $router,
    ) {}

    /**
     * List the auth guards the route authenticates against, in order.
     *
     * An Authenticate entry naming no guard resolves the application's default
     * guard, as the middleware itself does.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return list<string>
     */
    public function guards(Route $route): array
    {
        $guards = [];

        foreach ($this->parametersOf($route, Authenticate::class) as $parameters) {
            array_push($guards, ...($parameters === [] ? [$this->defaultGuard()] : $parameters));
        }

        return $guards;
    }

    /**
     * Determine whether the route requires an authenticated caller.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return bool
     */
    public function authenticates(Route $route): bool
    {
        return $this->parametersOf($route, Authenticate::class) !== [];
    }

    /**
     * Determine whether the route runs an authorization check.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return bool
     */
    public function authorizes(Route $route): bool
    {
        return $this->parametersOf($route, Authorize::class) !== [];
    }

    /**
     * Determine whether the route is rate limited.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return bool
     */
    public function throttles(Route $route): bool
    {
        return $this->parametersOf($route, ThrottleRequests::class) !== [];
    }

    /**
     * List the parameters of every resolved middleware entry of the given
     * class, one list per entry.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  class-string  $class
     * @return list<list<string>>
     */
    private function parametersOf(Route $route, string $class): array
    {
        $matches = [];

        foreach ($this->resolvedMiddleware($route) as $entry) {

            $parts = explode(':', $entry, 2);

            if (!is_a($parts[0], $class, true)) {
                continue;
            }

            $matches[] = array_values(array_filter(
                array_map('trim', explode(',', $parts[1] ?? '')),
                static fn (string $parameter): bool => $parameter !== '',
            ));
        }

        return $matches;
    }

    /**
     * Resolve the route's middleware as the router would dispatch it, keeping
     * only string entries and degrading to none when a misconfigured controller
     * makes the middleware unresolvable.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return list<string>
     */
    private function resolvedMiddleware(Route $route): array
    {
        try {
            $middleware = $this->router->gatherRouteMiddleware($route);
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            return [];
        }

        return array_values(array_filter($middleware, 'is_string'));
    }

    /**
     * Resolve the application's default auth guard.
     *
     * @return string
     */
    private function defaultGuard(): string
    {
        $guard = Config::get('auth.defaults.guard');

        return is_string($guard) && $guard !== '' ? $guard : self::DEFAULT_GUARD_FALLBACK;
    }
}
