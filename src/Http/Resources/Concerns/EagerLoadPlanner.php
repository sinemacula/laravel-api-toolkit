<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Contracts\Database\Eloquent\SupportsPartialRelations;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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

        $plain    = array_diff(array_unique($plain), array_keys($scoped));
        $closures = [];

        foreach ($scoped as $path => [$owner, $constraint, $limit, $aggregates]) {
            $closures[$path] = self::scope($owner, $constraint, $limit, $aggregates, self::takeColumnSelection($plain, $path));
        }

        $map = $plain === [] && $closures === [] ? [] : array_merge($plain, $closures);

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
     * resource already on the path ends the walk, so cycles terminate. A path
     * the walk cuts still loads the child's aggregates.
     *
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @param  string  $prefix
     * @param  array<int, string>  $plain
     * @param  array<string, array{string, (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null, int, list<AggregateLoad>}>  $scoped
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

            $fullPath    = self::makePrefixedPath($prefix, $relation);
            $childFields = self::resolveChildFields($definition);

            self::addEagerLoadPath($definition, $resourceClass . '::' . $field, $fullPath, self::resolveChildAggregates($definition, $childFields), $plain, $scoped);

            if ($childFields === []) {
                continue;
            }

            self::walkRelations((string) $definition->resource, $childFields, $fullPath, $plain, $scoped, $ancestors);
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

        $scoped[$fullPath] = [$owner, $constraint, $limit, $aggregates];
    }

    /**
     * Build the closure that owns a scoped path.
     *
     * A column selection runs first so the aggregates add to it, and the bound
     * runs last. A MorphTo loads each type through a fresh query that drops the
     * aggregate selects, so it loads none.
     *
     * @param  string  $owner
     * @param  (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null  $constraint
     * @param  int  $limit
     * @param  list<AggregateLoad>  $aggregates
     * @param  list<string>  $columns
     * @return \Closure(\Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>): void
     */
    private static function scope(string $owner, ?\Closure $constraint, int $limit, array $aggregates, array $columns): \Closure
    {
        return static function (EloquentRelation $relation) use ($owner, $constraint, $limit, $aggregates, $columns): void {

            if ($columns !== []) {
                $relation->select(self::qualifyColumns($relation, $columns));
            }

            if ($constraint !== null) {
                RelationWindow::constrain($relation, $constraint, $owner);
            }

            if (!$relation instanceof MorphTo) {
                foreach ($aggregates as $entry) {
                    $relation->withAggregate($entry['relation'], $entry['column'], $entry['function']);
                }
            }

            RelationWindow::bound($relation, $limit);
        };
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
