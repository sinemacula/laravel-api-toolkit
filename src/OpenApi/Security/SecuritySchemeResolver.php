<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Security;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;

/**
 * Derives a route's OpenAPI security requirement from its auth middleware.
 *
 * Authentication is read from standard Laravel config, orthogonal to
 * authorization: a route's `auth`/`auth:*` middleware yields a guard list (bare
 * `auth` resolves the default guard; `auth:user,guest` is an OR of both), each
 * guard's driver comes from `auth.guards.<guard>.driver`, and the driver maps
 * to a security scheme through the mapper. Guards mapping to different schemes
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
    /** The default guard assumed when none is configured */
    private const string DEFAULT_GUARD_FALLBACK = 'web';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Security\SecuritySchemeMapper  $mapper
     */
    public function __construct(

        /** The driver-to-scheme mapper. */
        private SecuritySchemeMapper $mapper,
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

        foreach ($this->guardsFor($route) as $guard) {

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
     * List the auth guards the route's middleware references, in order.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return list<string>
     */
    private function guardsFor(Route $route): array
    {
        $guards = [];

        foreach ($this->authMiddleware($route) as $middleware) {
            $guards = array_merge($guards, $this->guardsFromMiddleware($middleware));
        }

        return $guards;
    }

    /**
     * Gather the route's applied middleware as strings, degrading to none when
     * a misconfigured controller makes the middleware unresolvable.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return list<string>
     */
    private function authMiddleware(Route $route): array
    {
        try {
            $middleware = $route->gatherMiddleware();
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            return [];
        }

        return array_values(array_filter($middleware, 'is_string'));
    }

    /**
     * Parse the guards from a single middleware string, returning none when the
     * middleware is not an auth guard.
     *
     * @param  string  $middleware
     * @return list<string>
     */
    private function guardsFromMiddleware(string $middleware): array
    {
        if ($middleware === 'auth') {
            return [$this->defaultGuard()];
        }

        if (!str_starts_with($middleware, 'auth:')) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', substr($middleware, 5))),
            static fn (string $guard): bool => $guard !== '',
        ));
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
