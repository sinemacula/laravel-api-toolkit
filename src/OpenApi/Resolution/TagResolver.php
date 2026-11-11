<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use Illuminate\Routing\Route;
use SineMacula\ApiToolkit\OpenApi\Attributes\Tag;

/**
 * Resolves the OpenAPI tag an operation is grouped under.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class TagResolver
{
    /** The tag emitted for a route that yields no other name */
    private const string FALLBACK_TAG = 'default';

    /**
     * Resolve the tag for the operation.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  \Illuminate\Routing\Route  $route
     * @param  string|null  $schemaName
     * @return string
     */
    public function resolve(?string $controllerClass, string $action, Route $route, ?string $schemaName): string
    {
        $explicit = $this->explicitTag($controllerClass, $action);

        if ($explicit !== null) {
            return $explicit;
        }

        if ($schemaName !== null) {
            return $schemaName;
        }

        return $controllerClass === null
            ? $this->uriTag($route)
            : $this->controllerTag($controllerClass);
    }

    /**
     * Read the #[Tag] declared on the method, else on the controller class.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return string|null
     */
    private function explicitTag(?string $controllerClass, string $action): ?string
    {
        if ($controllerClass === null || !class_exists($controllerClass)) {
            return null;
        }

        $method = method_exists($controllerClass, $action)
            ? $this->tagFrom(new \ReflectionMethod($controllerClass, $action))
            : null;

        return $method ?? $this->tagFrom(new \ReflectionClass($controllerClass));
    }

    /**
     * Read the tag name from the first #[Tag] attribute on the reflector.
     *
     * @param  \ReflectionClass<object>|\ReflectionMethod  $reflector
     * @return string|null
     */
    private function tagFrom(\ReflectionClass|\ReflectionMethod $reflector): ?string
    {
        $attributes = $reflector->getAttributes(Tag::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->name;
    }

    /**
     * Derive the tag from the controller basename without a "Controller"
     * suffix.
     *
     * @param  class-string  $controllerClass
     * @return string
     */
    private function controllerTag(string $controllerClass): string
    {
        $base = class_basename($controllerClass);

        return (string) preg_replace('/Controller$/', '', $base) ?: $base;
    }

    /**
     * Derive the tag from the first non-parameter URI segment.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return string
     */
    private function uriTag(Route $route): string
    {
        foreach (explode('/', $route->uri()) as $segment) {
            if ($segment !== '' && !str_starts_with($segment, '{')) {
                return $segment;
            }
        }

        return self::FALLBACK_TAG;
    }
}
