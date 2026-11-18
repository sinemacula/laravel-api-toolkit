<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\RelationLimit;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Builds eager-load and aggregate maps from compiled schema relations.
 *
 * @phpstan-import-type AggregateLoad from \SineMacula\ApiToolkit\Contracts\ApiResourceInterface
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
final class EagerLoadPlanner
{
    /** @var array<string, array<int|string, mixed>> Eager-load maps, keyed by resource class + field signature */
    private static array $eagerLoadCache = [];

    /** @var array<string, list<AggregateLoad>> Aggregate entry lists, keyed by resource class + requested signature */
    private static array $aggregateCache = [];

    /**
     * Build a with()-ready eager-load map for the given fields.
     *
     * Numeric keys hold plain paths; string keys map scoped paths to closures.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @return array<int|string, mixed>
     */
    public static function buildEagerLoadMap(string $resourceClass, array $fields): array
    {
        $key = $resourceClass . '|' . implode("\0", $fields);

        if (isset(self::$eagerLoadCache[$key])) {
            return self::$eagerLoadCache[$key];
        }

        $plain  = [];
        $scoped = [];

        self::walkRelations($resourceClass, $fields, '', $plain, $scoped);

        $plain = array_values(array_diff(array_unique($plain), array_keys($scoped)));
        $map   = $plain === [] && $scoped === [] ? [] : array_merge($plain, $scoped);

        return self::$eagerLoadCache[$key] = $map;
    }

    /**
     * Clear the static eager-load and aggregate map memos.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$eagerLoadCache = [];
        self::$aggregateCache = [];
    }

    /**
     * Build a withAggregate-ready entry list for the given resource class.
     *
     * A non-empty request selects exactly its keys; otherwise the defaults.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>|null  $requested
     * @return list<AggregateLoad>
     */
    public static function buildAggregateMap(string $resourceClass, ?array $requested = null): array
    {
        $requested = $requested ?: null;
        $cacheKey  = $resourceClass . '|' . ($requested === null ? '*' : serialize($requested));

        if (isset(self::$aggregateCache[$cacheKey])) {
            return self::$aggregateCache[$cacheKey];
        }

        $entries = [];

        foreach (SchemaCompiler::compile($resourceClass)->getAggregateDefinitions() as $presentKey => $definition) {

            $included = $requested === null ? $definition->isDefault : in_array($presentKey, $requested, true);

            if (!$included) {
                continue;
            }

            $aliased    = $definition->relation . ' as ' . $definition->attribute();
            $constraint = $definition->constraint;

            $entries[] = [
                'relation' => $constraint instanceof \Closure ? [$aliased => $constraint] : $aliased,
                'column'   => $definition->column ?? '*',
                'function' => $definition->function->value,
            ];
        }

        return self::$aggregateCache[$cacheKey] = $entries;
    }

    /**
     * Determine whether the aggregate for the relation is already loaded.
     *
     * Accepts `relation`, `relation as alias` or `[aliased => constraint]`.
     *
     * @param  object  $resource
     * @param  mixed  $relation
     * @return bool
     */
    public static function isAggregateAttributeLoaded(object $resource, mixed $relation): bool
    {
        if (!method_exists($resource, 'getAttributes')) {
            return false;
        }

        $specification = is_array($relation) ? array_key_first($relation) : $relation;

        if (!is_string($specification)) {
            return false;
        }

        $position = strpos($specification, ' as ');
        $alias    = $position === false ? $specification : substr($specification, $position + 4);

        return array_key_exists($alias, $resource->getAttributes());
    }

    /**
     * Recursively walk relation definitions, accumulating eager-load paths.
     *
     * Every field recurses into its own child resource and projection, and a
     * resource already on the path ends the walk, so cycles terminate.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @param  string  $prefix
     * @param  array<int, string>  $plain
     * @param  array<string, mixed>  $scoped
     * @param  array<int, string>  $ancestors
     * @return void
     */
    private static function walkRelations(string $resourceClass, array $fields, string $prefix, array &$plain, array &$scoped, array $ancestors = []): void
    {
        if (in_array($resourceClass, $ancestors, true)) {
            return;
        }

        $ancestors[] = $resourceClass;

        $schema = SchemaCompiler::compile($resourceClass);

        foreach ($fields as $field) {

            $definition = $schema->getField($field);

            if ($definition === null) {
                continue;
            }

            self::collectExtraPaths($definition, $prefix, $plain);

            $relation = $definition->relation;

            if ($relation === null || $relation === '') {
                continue;
            }

            $fullPath = self::makePrefixedPath($prefix, $relation);

            self::addEagerLoadPath($definition, $resourceClass . '::' . $field, $fullPath, $plain, $scoped);
            self::recurseIntoChild($definition, $fullPath, $plain, $scoped, $ancestors);
        }
    }

    /**
     * Collect extra eager-load paths from a field definition.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  string  $prefix
     * @param  array<int, string>  $plain
     * @return void
     */
    private static function collectExtraPaths(CompiledFieldDefinition $definition, string $prefix, array &$plain): void
    {
        foreach ($definition->extras as $extra) {
            if ($extra === '') {
                continue;
            }

            $plain[] = self::makePrefixedPath($prefix, $extra);
        }
    }

    /**
     * Add the relation path as either a scoped or plain eager-load entry.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  string  $owner
     * @param  string  $fullPath
     * @param  array<int, string>  $plain
     * @param  array<string, mixed>  $scoped
     * @return void
     */
    private static function addEagerLoadPath(CompiledFieldDefinition $definition, string $owner, string $fullPath, array &$plain, array &$scoped): void
    {
        $constraint = $definition->constraint;
        $limit      = RelationLimit::for($definition);

        if ($constraint === null && $limit === 0) {
            $plain[] = $fullPath;

            return;
        }

        $scoped[$fullPath] = static function (EloquentRelation $relation) use ($constraint, $limit, $owner): void {

            if ($constraint !== null) {
                RelationWindow::constrain($relation, $constraint, $owner);
            }

            RelationWindow::bound($relation, $limit);
        };
    }

    /**
     * Recurse into the definition's child resource, if any.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  string  $fullPath
     * @param  array<int, string>  $plain
     * @param  array<string, mixed>  $scoped
     * @param  array<int, string>  $ancestors
     * @return void
     */
    private static function recurseIntoChild(CompiledFieldDefinition $definition, string $fullPath, array &$plain, array &$scoped, array $ancestors = []): void
    {
        if (!self::shouldRecurseIntoChild($definition)) {
            return;
        }

        /** @var string $childResource */
        $childResource = $definition->resource;
        $childFields   = self::resolveChildFields($definition, $childResource);

        if ($childFields === []) {
            return;
        }

        self::walkRelations($childResource, $childFields, $fullPath, $plain, $scoped, $ancestors);
    }

    /**
     * Build a dot-prefixed path from a prefix and suffix.
     *
     * @param  string  $prefix
     * @param  string  $suffix
     * @return string
     */
    private static function makePrefixedPath(string $prefix, string $suffix): string
    {
        return $prefix === '' ? $suffix : $prefix . '.' . $suffix;
    }

    /**
     * Determine whether the definition points to a child ApiResource.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @return bool
     */
    private static function shouldRecurseIntoChild(CompiledFieldDefinition $definition): bool
    {
        return $definition->resource !== null
            && $definition->resource !== ''
            && is_subclass_of($definition->resource, ApiResource::class);
    }

    /**
     * Resolve the child fields to traverse for a nested resource.
     *
     * Definition fields, else query fields, else defaults, else all fields.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  string  $resource
     * @return array<int, string>
     */
    private static function resolveChildFields(CompiledFieldDefinition $definition, string $resource): array
    {
        if ($definition->fields !== null && $definition->fields !== []) {
            return array_values(
                array_filter($definition->fields, static fn (string $f): bool => $f !== ''),
            );
        }

        $requested = self::getRequestedChildFields($resource);

        if ($requested !== []) {
            return $requested;
        }

        $defaults = $resource::getDefaultFields();

        return $defaults !== [] ? $defaults : $resource::getAllFields();
    }

    /**
     * Get the API-query-requested fields for a child resource type.
     *
     * @param  string  $resource
     * @return array<int, string>
     */
    private static function getRequestedChildFields(string $resource): array
    {
        if (!is_subclass_of($resource, ApiResource::class)) {
            return [];
        }

        $childType = $resource::getResourceType();
        $requested = ApiQuery::getFields($childType);

        if (!is_array($requested) || $requested === []) {
            return [];
        }

        return array_values(
            array_filter($requested, static fn ($f) => $f !== ''),
        );
    }
}
