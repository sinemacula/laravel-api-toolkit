<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;

/**
 * Excludes framework and tooling routes from the documented surface.
 *
 * A route is dropped when the class defining its handler - its controller, or a
 * closure's declaring namespace - begins with any configured namespace prefix.
 * The shipped default blocks the framework and common first-party tooling only,
 * so an organisation that splits its own internal routes into separate composer
 * packages keeps them documented; those namespaces are absent from the default.
 *
 * The configured list replaces the built-in default outright rather than
 * merging with it: the shipped config seeds the key with the default set so an
 * application adds or removes a prefix by editing that array, and the built-in
 * constant is used only as the fallback when the key is entirely unset. A
 * prefix matches on a namespace boundary, so `Illuminate\` excludes
 * `Illuminate\Routing\Foo` but never `IlluminateApp\Foo`. Matching is
 * case-sensitive and reflection is guarded, so a malformed handler degrades to
 * keeping the route rather than throwing.
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
     * Best-effort resolve the declaring namespace of the route's closure
     * handler, or null when the handler is not a closure or cannot be
     * reflected.
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
            $namespace = (new \ReflectionFunction($handler))->getNamespaceName();
        } catch (\Throwable) {
            return null;
        }

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
     * Read the configured blocklist, falling back to the built-in default when
     * the key is unset or malformed.
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
            array_map('strval', $configured),
            static fn (string $prefix): bool => $prefix !== '',
        ));
    }
}
