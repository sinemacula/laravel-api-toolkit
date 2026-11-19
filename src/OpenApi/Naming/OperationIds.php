<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Naming;

use Illuminate\Routing\Route;
use SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm;

/**
 * Derives the operationId of each documentable verb of a route.
 *
 * The base id is the declared one, else the route name, else the controller and
 * action, else the method and path. A path form appends the optional parameters
 * it includes, then each verb after the first appends itself. An id taken from
 * the method and path needs neither, as it spells the verb and the form's path.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class OperationIds
{
    /** The marker of the random names route caching gives unnamed routes */
    private const string GENERATED_NAME_MARKER = 'generated::';

    /** The action a single-action controller is invoked through */
    private const string INVOKABLE_ACTION = '__invoke';

    /** The boundaries between words, keeping a run of capitals whole */
    private const string WORD_BOUNDARY = '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/';

    /**
     * Derive the id of each verb on the path form, keyed by verb.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @param  list<string>  $verbs
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RoutePathForm  $form
     * @param  string|null  $declared
     * @return array<string, string>
     */
    public static function derive(Route $route, ?string $controllerClass, string $action, array $verbs, RoutePathForm $form, ?string $declared): array
    {
        $base = $declared ?? self::routeName($route) ?? ($controllerClass === null ? null : self::controllerId($controllerClass, $action));
        $base = $base === null ? null : implode('.', [$base, ...$form->optional]);
        $ids  = [];

        foreach ($verbs as $index => $verb) {
            $ids[$verb] = match (true) {
                $base  === null => self::pathId($verb, $form->path),
                $index === 0    => $base,
                default         => $base . '.' . $verb,
            };
        }

        return $ids;
    }

    /**
     * Read the route name, unless it is empty, a bare group prefix or generated
     * by route caching.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return string|null
     */
    private static function routeName(Route $route): ?string
    {
        $name = $route->getName();

        if (!is_string($name) || in_array($name, ['', '0'], true) || str_ends_with($name, '.') || str_contains($name, self::GENERATED_NAME_MARKER)) {
            return null;
        }

        return $name;
    }

    /**
     * Derive the id from the controller basename and the action.
     *
     * @param  class-string  $controllerClass
     * @param  string  $action
     * @return string
     */
    private static function controllerId(string $controllerClass, string $action): string
    {
        $base       = class_basename($controllerClass);
        $controller = self::kebab(preg_replace('/Controller$/', '', $base) ?: $base);

        return $action === self::INVOKABLE_ACTION ? $controller : $controller . '.' . self::kebab($action);
    }

    /**
     * Derive the id from the verb and the path template's segments, their
     * braces stripped.
     *
     * @param  string  $verb
     * @param  string  $path
     * @return string
     */
    private static function pathId(string $verb, string $path): string
    {
        $segments = explode('/', str_replace(['{', '}'], '', $path));

        return implode('.', array_filter([$verb, ...$segments], static fn (string $segment): bool => $segment !== ''));
    }

    /**
     * Kebab-case a PascalCase or camelCase word, keeping acronyms whole.
     *
     * @param  string  $value
     * @return string
     */
    private static function kebab(string $value): string
    {
        return strtolower((string) preg_replace(self::WORD_BOUNDARY, '-', $value));
    }
}
