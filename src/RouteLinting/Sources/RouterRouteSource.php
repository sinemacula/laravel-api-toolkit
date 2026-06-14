<?php

namespace SineMacula\ApiToolkit\RouteLinting\Sources;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use SineMacula\ApiToolkit\RouteLinting\Contracts\RouteSource;
use SineMacula\ApiToolkit\RouteLinting\Dto\RouteDescriptor;

/**
 * Route-source adapter backed by the Laravel router.
 *
 * Enumerates the live route table via `Router::getRoutes()` after a full
 * application boot — the same source that `route:list` consumes — and maps
 * each route to a `RouteDescriptor` DTO. Vendor routes (whose defining class
 * or closure resolves to a file under the `vendor/` directory) are excluded
 * from the returned set; the domain never sees a framework `Route` object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RouterRouteSource implements RouteSource
{
    /** @var string The path segment used to identify vendor-owned files. */
    private const string VENDOR_SEGMENT = DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;

    /**
     * Create a new router-backed route source.
     *
     * @param  \Illuminate\Routing\Router  $router
     */
    public function __construct(private readonly Router $router) {}

    /**
     * Enumerate every app-owned route, excluding vendor routes.
     *
     * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Dto\RouteDescriptor>
     */
    #[\Override]
    public function appRoutes(): array
    {
        $descriptors = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $descriptor = $this->toDescriptor($route);

            if (!$descriptor->isVendor) {
                $descriptors[] = $descriptor;
            }
        }

        return $descriptors;
    }

    /**
     * Map a single framework route to a descriptor DTO.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return \SineMacula\ApiToolkit\RouteLinting\Dto\RouteDescriptor
     */
    private function toDescriptor(Route $route): RouteDescriptor
    {
        return new RouteDescriptor(
            uri: $route->uri(),
            methods: $route->methods(),
            name: $route->getName(),
            isVendor: $this->isVendorRoute($route),
        );
    }

    /**
     * Determine whether a route belongs to a vendor package.
     *
     * Resolution strategy mirrors `route:list --except-vendor`:
     * - Closure routes: reflect the closure's defining file.
     * - Controller routes: reflect the controller class's defining file.
     * - Unresolvable sources default to app-owned (false) so no app route is
     *   silently dropped.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return bool
     */
    private function isVendorRoute(Route $route): bool
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof \Closure) {
            return $this->isClosureVendor($uses);
        }

        return is_string($uses) && $this->isControllerVendor($route);
    }

    /**
     * Determine whether a closure-backed route's defining file is under vendor.
     *
     * @param  \Closure(): void  $closure
     * @return bool
     */
    private function isClosureVendor(\Closure $closure): bool
    {
        try {
            $file = (new \ReflectionFunction($closure))->getFileName();
        } catch (\ReflectionException) {
            return false;
        }

        return $this->fileIsVendor($file ?: null);
    }

    /**
     * Determine whether a controller-backed route's defining class file is under vendor.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return bool
     */
    private function isControllerVendor(Route $route): bool
    {
        $controllerClass = $route->getControllerClass();

        if ($controllerClass === null) {
            return false;
        }

        return $this->fileIsVendor($this->classFile($controllerClass));
    }

    /**
     * Determine whether an absolute file path lives under a vendor directory.
     *
     * @param  string|null  $file
     * @return bool
     */
    private function fileIsVendor(?string $file): bool
    {
        if ($file === null) {
            return false;
        }

        return str_contains($file, self::VENDOR_SEGMENT);
    }

    /**
     * Resolve the absolute file path that defines a given class.
     *
     * @param  string  $class
     * @return string|null
     */
    private function classFile(string $class): ?string
    {
        try {
            return (new \ReflectionClass($class))->getFileName() ?: null;
        } catch (\ReflectionException) {
            return null;
        }
    }
}
