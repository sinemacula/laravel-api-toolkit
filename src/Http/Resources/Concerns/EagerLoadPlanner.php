<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Builds eager-load maps and count maps by traversing compiled schema relation
 * definitions.
 *
 * Produces with()-ready eager-load maps and withCount()-ready count maps from a
 * compiled schema, handling plain relations, scoped constraints, extra paths,
 * child resource recursion, and cycle prevention.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class EagerLoadPlanner
{
    /** @var array<string, array<int|string, mixed>> Memo of eager-load maps, keyed by resource class + field signature. */
    private static array $eagerLoadCache = [];

    /** @var array<string, array<int|string, mixed>> Memo of count maps, keyed by resource class + alias signature. */
    private static array $countCache = [];

    /**
     * Build a with()-ready eager-load map for the given fields.
     *
     * Returns a mixed array where numeric keys are plain eager-load paths and
     * string keys are scoped paths with constraint closures.
     *
     * The result is memoised per (resource class, field signature); the memo is
     * request-scoped because CacheManager::flush() clears it at request and
     * worker boundaries, so it never serves a map built against a different
     * request's child field selection.
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

        $plain   = [];
        $scoped  = [];
        $visited = [];

        self::walkRelations($resourceClass, $fields, '', $plain, $scoped, $visited);

        $map = $plain === [] && $scoped === [] ? [] : array_merge($plain, $scoped);

        return self::$eagerLoadCache[$key] = $map;
    }

    /**
     * Clear the static eager-load and count map memos.
     *
     * Invoked by CacheManager::flush() at request and worker boundaries.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$eagerLoadCache = [];
        self::$countCache     = [];
    }

    /**
     * Build a withCount-ready array for the given resource class.
     *
     * Returns a mixed array where numeric keys are plain count relations and
     * string keys are constrained count relations with closures.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>|null  $requestedAliases
     * @return array<int|string, mixed>
     */
    public static function buildCountMap(string $resourceClass, ?array $requestedAliases = null): array
    {
        $key = $resourceClass . '|' . ($requestedAliases === null ? '*' : implode("\0", $requestedAliases));

        if (isset(self::$countCache[$key])) {
            return self::$countCache[$key];
        }

        $schema = SchemaCompiler::compile($resourceClass);
        $with   = [];

        foreach ($schema->getCountDefinitions() as $presentKey => $definition) {

            if (!self::shouldIncludeCount($presentKey, $requestedAliases, $definition)) {
                continue;
            }

            // Alias each count by its presentation key so two counts on the
            // same relation (e.g. all + constrained) resolve to distinct
            // `{presentKey}_count` attributes instead of colliding on one.
            $aliased = $definition->relation . ' as ' . $definition->presentKey . '_count';

            $constraint = $definition->constraint;

            if ($constraint instanceof \Closure) {
                $with[$aliased] = $constraint;
            } else {
                $with[] = $aliased;
            }
        }

        return self::$countCache[$key] = $with;
    }

    /**
     * Recursively walk relation definitions, accumulating plain and scoped
     * eager-load paths.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @param  string  $prefix
     * @param  array<int, string>  $plain
     * @param  array<string, mixed>  $scoped
     * @param  array<string, bool>  $visited
     * @return void
     */
    private static function walkRelations(
        string $resourceClass,
        array $fields,
        string $prefix,
        array &$plain,
        array &$scoped,
        array &$visited
    ): void {

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

            if (self::wasVisited($visited, $resourceClass, $fullPath)) {
                continue;
            }

            self::markVisited($visited, $resourceClass, $fullPath);
            self::addEagerLoadPath($definition, $fullPath, $plain, $scoped);
            self::recurseIntoChild($definition, $fullPath, $plain, $scoped, $visited);
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
     * @param  string  $fullPath
     * @param  array<int, string>  $plain
     * @param  array<string, mixed>  $scoped
     * @return void
     */
    private static function addEagerLoadPath(
        CompiledFieldDefinition $definition,
        string $fullPath,
        array &$plain,
        array &$scoped
    ): void {
        $constraint = $definition->constraint;

        if (!($constraint instanceof \Closure)) {
            $plain[] = $fullPath;

            return;
        }

        $scoped[$fullPath] = self::wrapConstraint($constraint);
    }

    /**
     * Wrap a constraint closure to safely handle MorphTo, EloquentRelation,
     * and Builder instances.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void  $constraint
     * @return \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void
     */
    private static function wrapConstraint(\Closure $constraint): \Closure
    {
        return static function ($query) use ($constraint): void {

            if ($query instanceof MorphTo) {
                $constraint($query);

                return;
            }

            $builder = $query instanceof EloquentRelation ? $query->getQuery() : $query;

            if (!($builder instanceof Builder)) {
                return;
            }

            $constraint($builder);
        };
    }

    /**
     * If the definition points to a child resource, recurse into it.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  string  $fullPath
     * @param  array<int, string>  $plain
     * @param  array<string, mixed>  $scoped
     * @param  array<string, bool>  $visited
     * @return void
     */
    private static function recurseIntoChild(
        CompiledFieldDefinition $definition,
        string $fullPath,
        array &$plain,
        array &$scoped,
        array &$visited
    ): void {
        if (!self::shouldRecurseIntoChild($definition)) {
            return;
        }

        /** @var string $childResource */
        $childResource = $definition->resource;
        $childFields   = self::resolveChildFields($definition, $childResource);

        if ($childFields === []) {
            return;
        }

        self::walkRelations($childResource, $childFields, $fullPath, $plain, $scoped, $visited);
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
     * Check if a resource-class/path pair has already been visited.
     *
     * @param  array<string, bool>  $visited
     * @param  string  $resource
     * @param  string  $path
     * @return bool
     */
    private static function wasVisited(array $visited, string $resource, string $path): bool
    {
        return isset($visited[$resource . '|' . $path]);
    }

    /**
     * Mark a resource-class/path pair as visited.
     *
     * @param  array<string, bool>  $visited
     * @param  string  $resource
     * @param  string  $path
     * @return void
     */
    private static function markVisited(array &$visited, string $resource, string $path): void
    {
        $visited[$resource . '|' . $path] = true;
    }

    /**
     * Determine whether the definition points to a child ApiResource that
     * should be recursed into.
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
     * Reads from: (1) explicit fields on the definition, (2) API query for the
     * child resource type, (3) child default fields or all-fields.
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

    /**
     * Determine whether a count definition should be included based on the
     * requested aliases or the default flag.
     *
     * @param  string  $presentKey
     * @param  array<int, string>|null  $requested
     * @param  \SineMacula\ApiToolkit\Schema\CompiledCountDefinition  $definition
     * @return bool
     */
    private static function shouldIncludeCount(
        string $presentKey,
        ?array $requested,
        CompiledCountDefinition $definition
    ): bool {
        if (is_array($requested) && $requested !== []) {
            return in_array($presentKey, $requested, true);
        }

        return $definition->isDefault;
    }
}
