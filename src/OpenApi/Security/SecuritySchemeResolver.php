<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Security;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;

/**
 * Derives a route's OpenAPI security requirement from its auth middleware.
 *
 * Authentication is read from standard Laravel config, orthogonal to
 * authorization: the route's Authenticate middleware, resolved through groups
 * and aliases as the router dispatches it, yields a guard list (a bare `auth`
 * resolves the default guard; `auth:user,guest` is an OR of both), each guard's
 * driver comes from `auth.guards.<guard>.driver`, and the driver maps to a
 * security scheme through the mapper. Guards mapping to different schemes
 * become separate single-scheme requirements (an OR); guards mapping to the
 * same scheme collapse to one, never revealing the guard distinction. A route
 * with no auth middleware is public and yields an empty requirement list.
 * Authorization middleware (`can:`) is not authentication, so a route carrying
 * only `can:` is treated as public for scheme purposes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SecuritySchemeResolver
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeMapper  $mapper
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver  $middleware
     */
    public function __construct(

        /** The driver-to-scheme mapper. */
        private SecuritySchemeMapper $mapper,

        /** The resolver reading the route's auth guards from its middleware. */
        private RouteMiddlewareResolver $middleware,
    ) {}

    /**
     * Build the per-operation security value: an ordered, deduped list of
     * single-scheme requirement objects for the route's auth guards, or an
     * empty list when the route is public.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return list<array<string, list<string>>>
     */
    public function securityFor(Route $route): array
    {
        $requirements = [];
        $seen         = [];

        foreach ($this->middleware->guards($route) as $guard) {

            $scheme = $this->schemeForGuard($guard);

            if ($scheme === null || isset($seen[$scheme['name']])) {
                continue;
            }

            $seen[$scheme['name']] = true;
            $requirements[]        = [$scheme['name'] => []];
        }

        return $requirements;
    }

    /**
     * Resolve the definition for a referenced scheme name, or null when no
     * mapped driver emits it.
     *
     * @param  string  $name
     * @return array<string, mixed>|null
     */
    public function definitionFor(string $name): ?array
    {
        return $this->mapper->definitionFor($name);
    }

    /**
     * Resolve the scheme for a guard from its configured driver, or null when
     * the guard or its driver has no mapping.
     *
     * @param  string  $guard
     * @return array{name: string, definition: array<string, mixed>}|null
     */
    private function schemeForGuard(string $guard): ?array
    {
        $driver = $this->driverFor($guard);

        return $driver === null ? null : $this->mapper->schemeFor($driver);
    }

    /**
     * Read the configured driver for a guard, or null when the guard is absent
     * from config.
     *
     * @param  string  $guard
     * @return string|null
     */
    private function driverFor(string $guard): ?string
    {
        $driver = Config::get("auth.guards.{$guard}.driver");

        return is_string($driver) && $driver !== '' ? $driver : null;
    }
}
