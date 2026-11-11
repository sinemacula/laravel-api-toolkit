<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Security;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteMiddlewareResolver;

/**
 * Derives a route's OpenAPI security requirement from its auth middleware.
 *
 * Guards sharing a scheme collapse to one requirement so the guard distinction
 * is never revealed.
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
     * Build the deduplicated security requirements for the route's auth guards.
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
     * Resolve the definition for a referenced scheme name.
     *
     * @param  string  $name
     * @return array<string, mixed>|null
     */
    public function definitionFor(string $name): ?array
    {
        return $this->mapper->definitionFor($name);
    }

    /**
     * Resolve the scheme for a guard from its configured driver.
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
     * Read the configured driver for a guard.
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
