<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteKeySchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\RoutePatternTranslator;

/**
 * Builds a route's path parameters, typed from what the router enforces on each
 * segment, and the server variables of its domain.
 *
 * A parameter takes its `where` constraint, else the string-backed enum or
 * model the action binds, else a string. An explicit binder owns the lookup, so
 * it leaves an unconstrained parameter a string.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class PathParameterBuilder
{
    /** The schema of a segment nothing narrows */
    private const array STRING_SCHEMA = ['type' => 'string'];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\RoutePatternTranslator  $patterns
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\RouteKeySchemaResolver  $routeKeys
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry  $enums
     */
    public function __construct(

        /** The router holding the explicit binders. */
        private Router $router,

        /** The translator of `where` constraints. */
        private RoutePatternTranslator $patterns,

        /** The resolver of a bound model's route key schema. */
        private RouteKeySchemaResolver $routeKeys,

        /** The registry collecting enum classes referenced by a $ref. */
        private EnumSchemaRegistry $enums,
    ) {}

    /**
     * Build the route's named path parameters, each required, in the given
     * order.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  list<string>  $names
     * @return list<array{name: string, in: 'path', required: true, schema: array<string, mixed>}>
     */
    public function build(Route $route, array $names): array
    {
        $bound      = $this->boundClasses($route);
        $parameters = [];

        foreach ($names as $name) {
            $parameters[] = [
                'name'     => $name,
                'in'       => 'path',
                'required' => true,
                'schema'   => $this->schemaFor($route, $name, $bound[$name] ?? null),
            ];
        }

        return $parameters;
    }

    /**
     * Build a server variable for each parameter of the route's domain, in the
     * order the domain declares them.
     *
     * A variable has no schema, so only a `where` list of literals or a bound
     * string-backed enum narrows it, to an enum defaulting to its first value.
     * A name also in the URI takes the URI's value, so only its constraint
     * applies.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return array<string, array{enum?: non-empty-list<string>, default: string}>
     */
    public function hostVariables(Route $route): array
    {
        $bound     = $this->boundClasses($route);
        $uri       = $this->namesIn($route->uri());
        $variables = [];

        foreach ($this->namesIn((string) $route->getDomain()) as $name) {

            $class  = in_array($name, $uri, true) ? null : ($bound[$name] ?? null);
            $values = $this->constraintValues($route, $name) ?? $this->enumValues($name, $class);

            $variables[$name] = $values === null ? ['default' => $name] : ['enum' => $values, 'default' => $values[0]];
        }

        return $variables;
    }

    /**
     * List the parameter names a route template declares, as the router
     * compiles them.
     *
     * @param  string  $template
     * @return list<string>
     */
    private function namesIn(string $template): array
    {
        preg_match_all('/\{(.*?)\}/', $template, $matches);

        return array_map(static fn (string $name): string => trim($name, '?'), $matches[1]);
    }

    /**
     * List the values a parameter's `where` constraint accepts when it is a
     * list of literals.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  string  $name
     * @return non-empty-list<string>|null
     */
    private function constraintValues(Route $route, string $name): ?array
    {
        $constraint = $route->wheres[$name] ?? null;

        return is_string($constraint) ? $this->patterns->literals($constraint) : null;
    }

    /**
     * List the values of the string-backed enum the action binds to a parameter
     * no explicit binder owns.
     *
     * @param  string  $name
     * @param  class-string|null  $class
     * @return non-empty-list<string>|null
     */
    private function enumValues(string $name, ?string $class): ?array
    {
        if ($class === null || !is_subclass_of($class, \BackedEnum::class) || $this->router->getBindingCallback($name) !== null) {
            return null;
        }

        /** @var list<string> $values */
        $values = array_column($class::cases(), 'value');

        return $values === [] ? null : $values;
    }

    /**
     * Map each route parameter to the enum or routable class the action binds
     * to it.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return array<string, class-string>
     */
    private function boundClasses(Route $route): array
    {
        $names     = $route->parameterNames();
        $signature = [
            ...$route->signatureParameters(['backedEnum' => true]),
            ...$route->signatureParameters(['subClass' => UrlRoutable::class]),
        ];
        $bound = [];

        foreach ($signature as $parameter) {

            $name = $this->routeParameterName($parameter->getName(), $names);

            if ($name === null) {
                continue;
            }

            /** @var class-string $class */
            $class = Reflector::getParameterClassName($parameter);

            $bound[$name] = $class;
        }

        return $bound;
    }

    /**
     * Match a signature parameter to a route parameter as Laravel binds it.
     *
     * @param  string  $parameter
     * @param  array<int, string>  $names
     * @return string|null
     */
    private function routeParameterName(string $parameter, array $names): ?string
    {
        foreach ([$parameter, Str::snake($parameter)] as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resolve one parameter's schema: constraint, then binding, then string.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  string  $name
     * @param  class-string|null  $class
     * @return array<string, mixed>
     */
    private function schemaFor(Route $route, string $name, ?string $class): array
    {
        $constraint = $route->wheres[$name] ?? null;
        $schema     = is_string($constraint) ? $this->patterns->translate($constraint) : null;

        if ($schema !== null) {
            return $schema;
        }

        if ($class === null || $this->router->getBindingCallback($name) !== null) {
            return self::STRING_SCHEMA;
        }

        return $this->bindingSchema($route, $name, $class) ?? self::STRING_SCHEMA;
    }

    /**
     * Resolve the schema of the enum or model bound to a parameter.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  string  $name
     * @param  class-string  $class
     * @return array<string, mixed>|null
     */
    private function bindingSchema(Route $route, string $name, string $class): ?array
    {
        if (enum_exists($class)) {
            return ['$ref' => $this->enums->reference($class)];
        }

        if (!is_subclass_of($class, Model::class)) {
            return null;
        }

        return $this->routeKeys->resolve($class, $route->bindingFieldFor($name), $route->allowsTrashedBindings());
    }
}
