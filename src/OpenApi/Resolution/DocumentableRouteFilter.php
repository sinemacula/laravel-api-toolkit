<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteAction;
use Illuminate\Routing\RouteFileRegistrar;
use Illuminate\Support\ServiceProvider;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Native;
use Laravel\SerializableClosure\Serializers\Signed;
use Laravel\SerializableClosure\Support\SelfReference;
use Laravel\SerializableClosure\UnsignedSerializableClosure;
use SineMacula\ApiToolkit\Config\OpenApiSettings;

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

    /** The classes that require route files, scoping each closure declared in one */
    private const array ROUTE_FILE_LOADERS = [RouteFileRegistrar::class, ServiceProvider::class];

    /** The classes a cached route's serialized closure may be restored from */
    private const array SERIALIZED_CLOSURE_CLASSES = [
        SerializableClosure::class,
        UnsignedSerializableClosure::class,
        Native::class,
        Signed::class,
        SelfReference::class,
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
     * A closure declared in a route file is scoped to the class that required
     * the file, which says nothing about where it was declared.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return string|null
     */
    private function closureNamespace(Route $route): ?string
    {
        $handler = $this->closure($route);

        if ($handler === null) {
            return null;
        }

        $reflection = new \ReflectionFunction($handler);
        $scope      = $reflection->getClosureScopeClass();

        // PHP 8.4 reports no namespace for a closure, so prefer its scope class
        $namespace = $scope !== null && !in_array($scope->getName(), self::ROUTE_FILE_LOADERS, true)
            ? $scope->getNamespaceName()
            : $reflection->getNamespaceName();

        return $namespace === '' ? null : $namespace;
    }

    /**
     * Resolve the route's closure handler, restoring one a cached route table
     * serialized.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return (\Closure(mixed ...): mixed)|null
     */
    private function closure(Route $route): ?\Closure
    {
        $handler = $route->getAction('uses');

        if (is_string($handler) && RouteAction::containsSerializedClosure(['uses' => $handler])) {
            $handler = $this->restore($handler);
        }

        return $handler instanceof \Closure ? $handler : null;
    }

    /**
     * Restore a serialized closure as the router does before running it.
     *
     * @param  string  $serialized
     * @return (\Closure(mixed ...): mixed)|null
     */
    private function restore(string $serialized): ?\Closure
    {
        try {
            $wrapper = unserialize($serialized, ['allowed_classes' => self::SERIALIZED_CLOSURE_CLASSES]);

            return $wrapper instanceof SerializableClosure || $wrapper instanceof UnsignedSerializableClosure
                ? $wrapper->getClosure()
                : null;
        } catch (\Throwable) {
            return null;
        }
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
        return OpenApiSettings::excludedNamespaces();
    }
}
