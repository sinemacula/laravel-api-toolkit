<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\RelationLimit;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Builds eager-load, count and aggregate maps from compiled schema relations.
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
    /** The global scope that orders a bounded relation by its related key */
    private const string KEY_ORDER_SCOPE = 'api-toolkit:relation-key-order';

    /** @var array<string, array<int|string, mixed>> Eager-load maps, keyed by resource class + field signature */
    private static array $eagerLoadCache = [];

    /** @var array<string, array<int|string, mixed>> Count maps, keyed by resource class + alias signature */
    private static array $countCache = [];

    /** @var array<string, list<AggregateLoad>> Aggregate entry lists, keyed by metric:resource class + requested signature */
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

        $plain   = [];
        $scoped  = [];
        $visited = [];

        self::walkRelations($resourceClass, $fields, '', $plain, $scoped, $visited);

        $map = $plain === [] && $scoped === [] ? [] : array_merge($plain, $scoped);

        return self::$eagerLoadCache[$key] = $map;
    }

    /**
     * Clear the static eager-load, count, and aggregate map memos.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$eagerLoadCache = [];
        self::$countCache     = [];
        self::$aggregateCache = [];
    }

    /**
     * Build a withCount-ready array for the given resource class.
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

            // Alias by present key so two counts on one relation do not collide
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
     * Build a withSum-ready entry list for the given resource class.
     *
     * @param  string  $resourceClass
     * @param  array<string, mixed>|null  $requested
     * @return list<AggregateLoad>
     */
    public static function buildSumMap(string $resourceClass, ?array $requested = null): array
    {
        return self::buildAggregateMap($resourceClass, 'sum', $requested);
    }

    /**
     * Build a withAvg-ready entry list for the given resource class.
     *
     * @param  string  $resourceClass
     * @param  array<string, mixed>|null  $requested
     * @return list<AggregateLoad>
     */
    public static function buildAvgMap(string $resourceClass, ?array $requested = null): array
    {
        return self::buildAggregateMap($resourceClass, 'avg', $requested);
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
     * Build a withSum/withAvg-ready entry list for the given metric.
     *
     * @param  string  $resourceClass
     * @param  string  $metric
     * @param  array<string, mixed>|null  $requested
     * @return list<AggregateLoad>
     */
    private static function buildAggregateMap(string $resourceClass, string $metric, ?array $requested): array
    {
        $cacheKey = $metric . ':' . $resourceClass . '|' . (!$requested ? '*' : json_encode($requested));

        if (isset(self::$aggregateCache[$cacheKey])) {
            return self::$aggregateCache[$cacheKey];
        }

        $schema  = SchemaCompiler::compile($resourceClass);
        $entries = [];

        foreach ($schema->getAggregateDefinitions() as $definition) {

            if ($definition->metric !== $metric) {
                continue;
            }

            if (!self::shouldIncludeAggregate($definition, $requested)) {
                continue;
            }

            // The alias must match the attribute name the resolver reads back
            $aliased    = $definition->relation . ' as ' . $definition->presentKey . '_' . $metric . '_' . $definition->column;
            $constraint = $definition->constraint;

            $entries[] = [
                'relation' => $constraint instanceof \Closure ? [$aliased => $constraint] : $aliased,
                'column'   => $definition->column,
            ];
        }

        return self::$aggregateCache[$cacheKey] = $entries;
    }

    /**
     * Recursively walk relation definitions, accumulating eager-load paths.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @param  string  $prefix
     * @param  array<int, string>  $plain
     * @param  array<string, mixed>  $scoped
     * @param  array<string, bool>  $visited
     * @param  array<int, string>  $ancestors
     * @return void
     */
    private static function walkRelations(string $resourceClass, array $fields, string $prefix, array &$plain, array &$scoped, array &$visited, array $ancestors = []): void
    {
        // Visited keys grow with the path, so cycles are cut by ancestry
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

            if (self::wasVisited($visited, $resourceClass, $fullPath)) {
                continue;
            }

            self::markVisited($visited, $resourceClass, $fullPath);
            self::addEagerLoadPath($definition, $resourceClass . '::' . $field, $fullPath, $plain, $scoped);
            self::recurseIntoChild($definition, $fullPath, $plain, $scoped, $visited, $ancestors);
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
                self::applyConstraint($relation, $constraint, $owner);
            }

            if ($limit === 0 || !RelationLimit::appliesTo($relation)) {
                return;
            }

            self::applyLimit($relation, $limit);
        };
    }

    /**
     * Run the field's constraint on the relation, refusing one that bounds it.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @param  \Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void  $constraint
     * @param  string  $owner
     * @return void
     *
     * @throws \LogicException
     */
    private static function applyConstraint(EloquentRelation $relation, \Closure $constraint, string $owner): void
    {
        $query  = $relation->getQuery()->getQuery();
        $before = self::bounds($query);

        $constraint($relation);

        if (self::bounds($query) === $before) {
            return;
        }

        throw new \LogicException(sprintf('The constraint on %s may not limit or offset the relation; declare limit() on the relation instead', $owner));
    }

    /**
     * Return the limit and offset clauses of the given query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return list<mixed>
     */
    private static function bounds(QueryBuilder $query): array
    {
        return [$query->limit, $query->offset, $query->groupLimit, $query->unionLimit, $query->unionOffset];
    }

    /**
     * Bound the relation per parent, keeping one extra row as a truncation
     * probe.
     *
     * The key order is a global scope, so it runs after the model's own scopes.
     * A bound the relation method already declares tighter is kept.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @param  int  $limit
     * @return void
     */
    private static function applyLimit(EloquentRelation $relation, int $limit): void
    {
        $key = $relation->getRelated()->getQualifiedKeyName();

        $relation->getQuery()->withGlobalScope(self::KEY_ORDER_SCOPE, static function (Builder $query) use ($key): void {
            $query->orderBy($key);
        });

        $existing = $relation->getQuery()->getQuery()->groupLimit['value'] ?? null;

        $relation->limit(is_int($existing) ? min($existing, $limit + 1) : $limit + 1);
    }

    /**
     * Recurse into the definition's child resource, if any.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  string  $fullPath
     * @param  array<int, string>  $plain
     * @param  array<string, mixed>  $scoped
     * @param  array<string, bool>  $visited
     * @param  array<int, string>  $ancestors
     * @return void
     */
    private static function recurseIntoChild(CompiledFieldDefinition $definition, string $fullPath, array &$plain, array &$scoped, array &$visited, array $ancestors = []): void
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

        self::walkRelations($childResource, $childFields, $fullPath, $plain, $scoped, $visited, $ancestors);
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

    /**
     * Determine whether a count is requested, or included by default.
     *
     * @param  string  $presentKey
     * @param  array<int, string>|null  $requested
     * @param  \SineMacula\ApiToolkit\Schema\CompiledCountDefinition  $definition
     * @return bool
     */
    private static function shouldIncludeCount(string $presentKey, ?array $requested, CompiledCountDefinition $definition): bool
    {
        if (is_array($requested) && $requested !== []) {
            return in_array($presentKey, $requested, true);
        }

        return $definition->isDefault;
    }

    /**
     * Determine whether an aggregate is requested, or included by default.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition  $definition
     * @param  array<string, mixed>|null  $requested
     * @return bool
     */
    private static function shouldIncludeAggregate(CompiledAggregateDefinition $definition, ?array $requested): bool
    {
        if (is_array($requested) && $requested !== []) {
            $columns = $requested[$definition->relation] ?? null;

            return is_array($columns) && in_array($definition->column, $columns, true);
        }

        return $definition->isDefault;
    }
}
