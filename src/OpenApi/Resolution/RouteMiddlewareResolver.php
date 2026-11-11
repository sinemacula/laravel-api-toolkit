<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;

/**
 * Reads the authentication, authorization and throttling facts of a route.
 *
 * Middleware is resolved as the router dispatches it, so groups, aliases and
 * exclusions apply.
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
        return $this->parametersOf($route, Authenticate::class)              !== []
            || $this->parametersOf($route, AuthenticateWithBasicAuth::class) !== [];
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
     * List the parameters of each resolved middleware entry of the given class.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  class-string  $class
     * @return list<array<int, string>>
     */
    private function parametersOf(Route $route, string $class): array
    {
        $matches = [];

        foreach ($this->resolvedMiddleware($route) as $entry) {

            $parts = explode(':', $entry, 2);

            if (!is_a($parts[0], $class, true)) {
                continue;
            }

            $matches[] = array_filter(
                array_map('trim', explode(',', $parts[1] ?? '')),
                static fn (string $parameter): bool => $parameter !== '',
            );
        }

        return $matches;
    }

    /**
     * Resolve the route's string middleware as the router would dispatch it.
     *
     * A misconfigured controller degrades to none so the export is not aborted.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return array<int, string>
     */
    private function resolvedMiddleware(Route $route): array
    {
        try {
            $middleware = $this->router->gatherRouteMiddleware($route);
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            return [];
        }

        return array_filter($middleware, 'is_string');
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
