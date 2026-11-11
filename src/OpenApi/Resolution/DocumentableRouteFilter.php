<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;

/**
 * Excludes framework and tooling routes from the documented surface.
 *
 * The configured namespace list replaces the default; prefixes match on a
 * namespace boundary.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DocumentableRouteFilter
{
    /** The namespace prefixes excluded from documentation by default */
    public const array DEFAULT_NAMESPACES = [
        'Illuminate\\',
        'Laravel\Horizon',
        'Laravel\Telescope',
        'Laravel\Sanctum',
        'Laravel\Fortify',
        'Laravel\Passport',
        'Laravel\Nova',
        'Spatie\LaravelIgnition',
        'Spatie\Ignition',
        'Barryvdh\\',
        'Clockwork\\',
    ];

    /**
     * Determine whether the route is excluded from documentation.
     *
     * @param  string|null  $controllerClass
     * @param  \Illuminate\Routing\Route  $route
     * @return bool
     */
    public function isExcluded(?string $controllerClass, Route $route): bool
    {
        $namespace = $controllerClass ?? $this->closureNamespace($route);

        if ($namespace === null || $namespace === '') {
            return false;
        }

        foreach ($this->blocklist() as $prefix) {
            if ($this->matchesPrefix($namespace, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the declaring namespace of the route's closure handler, if any.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return string|null
     */
    private function closureNamespace(Route $route): ?string
    {
        $handler = $route->getAction('uses');

        if (!$handler instanceof \Closure) {
            return null;
        }

        try {
            $reflection = new \ReflectionFunction($handler);
        } catch (\Throwable) {
            return null;
        }

        // PHP 8.4 reports no namespace for a closure, so prefer its scope class
        $scope     = $reflection->getClosureScopeClass();
        $namespace = $scope !== null ? $scope->getNamespaceName() : $reflection->getNamespaceName();

        return $namespace === '' ? null : $namespace;
    }

    /**
     * Determine whether the namespace begins with the prefix on a namespace
     * boundary.
     *
     * @param  string  $namespace
     * @param  string  $prefix
     * @return bool
     */
    private function matchesPrefix(string $namespace, string $prefix): bool
    {
        $boundary = rtrim($prefix, '\\');

        if ($boundary === '') {
            return false;
        }

        return $namespace === $boundary || str_starts_with($namespace, $boundary . '\\');
    }

    /**
     * Read the configured blocklist, falling back to the default when invalid.
     *
     * @return list<string>
     */
    private function blocklist(): array
    {
        $configured = Config::get('api-toolkit.openapi.exclude.namespaces', self::DEFAULT_NAMESPACES);

        if (!is_array($configured)) {
            return self::DEFAULT_NAMESPACES;
        }

        return array_values(array_filter(
            array_map(static fn (mixed $prefix): string => is_string($prefix) || $prefix instanceof \Stringable ? (string) $prefix : '', $configured),
            static fn (string $prefix): bool => $prefix !== '',
        ));
    }
}
