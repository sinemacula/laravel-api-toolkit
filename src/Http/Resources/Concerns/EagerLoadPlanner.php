<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Contracts\Database\Eloquent\SupportsPartialRelations;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\ChildProjection;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\RelationLimit;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Builds eager-load, projection and aggregate maps from compiled schema
 * relations.
 *
 * @phpstan-import-type AggregateLoad from \SineMacula\ApiToolkit\Contracts\ApiResourceInterface
 *
 * @phpstan-type EagerRelation \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>
 * @phpstan-type PlannedProjection array{resource: class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>|null, fields: list<string>, opaque: bool}
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

    /** @var array<string, array<string, \SineMacula\ApiToolkit\Schema\ChildProjection>> Projection plans, keyed as the eager-load maps */
    private static array $projectionCache = [];

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
        $key = self::planKey($resourceClass, $fields);

        if (!isset(self::$eagerLoadCache[$key])) {
            self::plan($resourceClass, $fields, $key);
        }

        return self::$eagerLoadCache[$key];
    }

    /**
     * Build the projection plan for the given fields: the child resource and
     * fields over every relation path the eager-load map loads.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @return array<string, \SineMacula\ApiToolkit\Schema\ChildProjection>
     */
    public static function buildProjectionPlan(string $resourceClass, array $fields): array
    {
        $key = self::planKey($resourceClass, $fields);

        if (!isset(self::$projectionCache[$key])) {
            self::plan($resourceClass, $fields, $key);
        }

        return self::$projectionCache[$key];
    }

    /**
     * Clear the static eager-load, projection and aggregate memos.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$eagerLoadCache  = [];
        self::$aggregateCache  = [];
        self::$projectionCache = [];
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
     * Return the key the eager-load map and projection plan are memoised under.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @return string
     */
    private static function planKey(string $resourceClass, array $fields): string
    {
        return $resourceClass . '|' . implode("\0", $fields);
    }

    /**
     * Walk the fields once, memoising both the eager-load map and the
     * projection plan under the key.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @param  string  $key
     * @return void
     */
    private static function plan(string $resourceClass, array $fields, string $key): void
    {
        $plain       = [];
        $scoped      = [];
        $projections = [];

        self::walkRelations($resourceClass, $fields, '', $plain, $scoped, $projections);

        $plain    = array_diff(array_unique($plain), array_keys($scoped));
        $closures = [];

        foreach ($scoped as $path => [$owner, $constraint, $limit, $aggregates]) {
            $closures[$path] = self::scope($owner, $constraint, $limit, $aggregates, self::takeColumnSelection($plain, $path));
        }

        self::$eagerLoadCache[$key]  = $plain === [] && $closures === [] ? [] : array_merge($plain, $closures);
        self::$projectionCache[$key] = self::finaliseProjections($projections);
    }

    /**
     * Recursively walk relation definitions, accumulating eager-load paths.
     *
     * Every field recurses into its own child resource and projection, and a
     * resource already on the path ends the walk, so cycles terminate. A path
     * the walk cuts still loads the child's aggregates.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @param  string  $prefix
     * @param  array<int, string>  $plain
     * @param  array<string, array{string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null, int, list<AggregateLoad>}>  $scoped
     * @param  array<string, PlannedProjection>  $projections
     * @param  array<int, string>  $ancestors
     * @return void
     */
    private static function walkRelations(string $resourceClass, array $fields, string $prefix, array &$plain, array &$scoped, array &$projections, array $ancestors = []): void
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

            self::collectExtraPaths($definition, $prefix, $plain, $projections);

            $relation = $definition->relation;

            if ($relation === null || $relation === '') {
                continue;
            }

            $fullPath    = self::makePrefixedPath($prefix, $relation);
            $childFields = self::resolveChildFields($definition);

            self::addEagerLoadPath($definition, $resourceClass . '::' . $field, $fullPath, self::resolveChildAggregates($definition, $childFields), $plain, $scoped);
            self::addProjection($definition, $fullPath, $childFields, $projections);

            if ($childFields === []) {
                continue;
            }

            self::walkRelations((string) $definition->resource, $childFields, $fullPath, $plain, $scoped, $projections, $ancestors);
        }
    }

    /**
     * Collect extra eager-load paths from a field definition.
     *
     * Whatever reads an extra path may read any of its columns, so every path
     * from the prefix down to it is opaque.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  string  $prefix
     * @param  array<int, string>  $plain
     * @param  array<string, PlannedProjection>  $projections
     * @return void
     */
    private static function collectExtraPaths(CompiledFieldDefinition $definition, string $prefix, array &$plain, array &$projections): void
    {
        foreach ($definition->extras as $extra) {
            if ($extra === '') {
                continue;
            }

            $plain[] = self::makePrefixedPath($prefix, $extra);
            $path    = $prefix;

            foreach (explode('.', Str::before($extra, ':')) as $segment) {
                $path               = self::makePrefixedPath($path, $segment);
                $projections[$path] = ['opaque' => true] + ($projections[$path] ?? ['resource' => null, 'fields' => []]);
            }
        }
    }

    /**
     * Add the relation path as either a scoped or plain eager-load entry.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  string  $owner
     * @param  string  $fullPath
     * @param  list<AggregateLoad>  $aggregates
     * @param  array<int, string>  $plain
     * @param  array<string, array{string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null, int, list<AggregateLoad>}>  $scoped
     * @return void
     */
    private static function addEagerLoadPath(CompiledFieldDefinition $definition, string $owner, string $fullPath, array $aggregates, array &$plain, array &$scoped): void
    {
        $constraint = $definition->constraint;
        $limit      = RelationLimit::for($definition);

        if ($constraint === null && $limit === 0 && $aggregates === []) {
            $plain[] = $fullPath;

            return;
        }

        // Another field over the same path keeps the aggregates it selected
        $planned = $scoped[$fullPath][3] ?? [];

        $scoped[$fullPath] = [$owner, $constraint, $limit, [...$planned, ...array_filter($aggregates, static fn (array $entry): bool => !in_array($entry, $planned, true))]];
    }

    /**
     * Record the child resource and fields the relation renders over the path.
     *
     * Its models are opaque when an accessor or a raw relation renders them.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  string  $path
     * @param  array<int, string>  $childFields
     * @param  array<string, PlannedProjection>  $projections
     * @return void
     */
    private static function addProjection(CompiledFieldDefinition $definition, string $path, array $childFields, array &$projections): void
    {
        /** @var class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>|null $resource */
        $resource = self::shouldRecurseIntoChild($definition) ? $definition->resource : null;
        $planned  = $projections[$path] ?? ['resource' => null, 'fields' => [], 'opaque' => false];

        $projections[$path] = [
            'resource' => $resource ?? $planned['resource'],
            'fields'   => array_values(array_unique([...$planned['fields'], ...$childFields])),
            'opaque'   => $planned['opaque'] || $resource === null || $definition->accessor !== null,
        ];
    }

    /**
     * Build the projection plan, dropping paths no child resource renders and
     * cascading opacity to every path beneath an opaque one.
     *
     * Every path is planned after its parent, so one pass cascades the flag.
     *
     * @param  array<string, PlannedProjection>  $projections
     * @return array<string, \SineMacula\ApiToolkit\Schema\ChildProjection>
     */
    private static function finaliseProjections(array $projections): array
    {
        $plan = [];

        foreach (array_keys($projections) as $path) {

            // A top-level path reads its own flag as its parent's
            $projections[$path]['opaque'] = $projections[$path]['opaque'] || $projections[Str::beforeLast($path, '.')]['opaque'];

            $projection = $projections[$path];

            if ($projection['resource'] === null) {
                continue;
            }

            $plan[$path] = new ChildProjection($projection['resource'], $projection['fields'], $projection['opaque']);
        }

        return $plan;
    }

    /**
     * Build the closure that owns a scoped path.
     *
     * A column selection runs first so the aggregates add to it, and the bound
     * runs last. A MorphTo loads each type through a fresh query that drops the
     * aggregate selects, so it loads none. The trashed gate passes a decorator
     * for the aggregates.
     *
     * @param  string  $owner
     * @param  (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null  $constraint
     * @param  int  $limit
     * @param  list<AggregateLoad>  $aggregates
     * @param  list<string>  $columns
     * @return \Closure(EagerRelation, (\Closure(list<AggregateLoad>): list<AggregateLoad>)|null=): void
     */
    private static function scope(string $owner, ?\Closure $constraint, int $limit, array $aggregates, array $columns): \Closure
    {
        return static function (EloquentRelation $relation, ?\Closure $decorateAggregates = null) use ($owner, $constraint, $limit, $aggregates, $columns): void {

            if ($columns !== []) {
                $relation->select(self::qualifyColumns($relation, $columns));
            }

            if ($constraint !== null) {
                RelationWindow::constrain($relation, $constraint, $owner);
            }

            self::loadAggregates($relation, $decorateAggregates === null ? $aggregates : $decorateAggregates($aggregates));

            RelationWindow::bound($relation, $limit);
        };
    }

    /**
     * Load the aggregates on the relation, unless it is a MorphTo.
     *
     * @param  EagerRelation  $relation
     * @param  list<AggregateLoad>  $aggregates
     * @return void
     */
    private static function loadAggregates(EloquentRelation $relation, array $aggregates): void
    {
        if ($relation instanceof MorphTo) {
            return;
        }

        foreach ($aggregates as $entry) {
            $relation->withAggregate($entry['relation'], $entry['column'], $entry['function']);
        }
    }

    /**
     * Remove the path's `relation:columns` entries and return their columns.
     *
     * @param  array<int, string>  $plain
     * @param  string  $path
     * @return list<string>
     */
    private static function takeColumnSelection(array &$plain, string $path): array
    {
        $columns = [];

        foreach ($plain as $index => $entry) {

            if (!str_starts_with($entry, $path . ':')) {
                continue;
            }

            $columns = [...$columns, ...explode(',', substr($entry, strlen($path) + 1))];

            unset($plain[$index]);
        }

        return array_values(array_unique($columns));
    }

    /**
     * Qualify the selected columns where Laravel's `relation:columns` does.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function qualifyColumns(EloquentRelation $relation, array $columns): array
    {
        $qualify = $relation instanceof BelongsToMany
            || $relation instanceof HasOneOrManyThrough
            || ($relation instanceof SupportsPartialRelations && $relation->isOneOfMany());

        return $qualify ? array_map($relation->getRelated()->qualifyColumn(...), $columns) : $columns;
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
     * Resolve the fields a child resource renders under the relation.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @return array<int, string>
     */
    private static function resolveChildFields(CompiledFieldDefinition $definition): array
    {
        if (!self::shouldRecurseIntoChild($definition)) {
            return [];
        }

        /** @var class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource> $child */
        $child = $definition->resource;

        return [...FieldResolver::effectiveFields($child, $definition->fields, false), ...$child::getFixedFields()];
    }

    /**
     * Resolve the aggregates a child resource renders under the relation.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  array<int, string>  $childFields
     * @return list<AggregateLoad>
     */
    private static function resolveChildAggregates(CompiledFieldDefinition $definition, array $childFields): array
    {
        if (!in_array('aggregates', $childFields, true)) {
            return [];
        }

        /** @var class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource> $child */
        $child = $definition->resource;

        return self::buildAggregateMap($child, ApiQuery::getAggregates($child::getResourceType()) ?? []);
    }
}
