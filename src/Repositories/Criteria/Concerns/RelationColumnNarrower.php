<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Config\ResourceSettings;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Schema\ChildProjection;
use SineMacula\ApiToolkit\Schema\ColumnNarrower;
use SineMacula\ApiToolkit\Schema\ColumnProjection;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SafetySetDeriver;

/**
 * Narrows each eager-loaded relation to the columns its child resource renders.
 *
 * A path is narrowed only when the child resource alone reads its models, its
 * relation class is recognised, and no relation in the graph sets an inverse,
 * which would replace a nested load with the narrowed parent.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RelationColumnNarrower
{
    /**
     * Create a new relation column narrower.
     *
     * @param  \SineMacula\ApiToolkit\Schema\SafetySetDeriver  $safetySetDeriver
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     */
    public function __construct(

        /** Derives the safety set each narrowed relation must retain. */
        private SafetySetDeriver $safetySetDeriver,

        /** Resolves each planned relation and reads its keys and columns. */
        private SchemaIntrospectionProvider $schemaIntrospector,
    ) {}

    /**
     * Decorate the eager-load map so each provable path selects only the
     * columns its child resource renders.
     *
     * A field its level cannot prove, such as one over a model accessor, may
     * read any loaded relation, so every relation beneath that level loads in
     * full.
     *
     * @param  array<int|string, mixed>  $with
     * @param  \Illuminate\Database\Eloquent\Model  $root
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @param  array<string, \SineMacula\ApiToolkit\Schema\ChildProjection>  $plan
     * @return array<int|string, mixed>
     */
    public function decorate(array $with, Model $root, string $resourceClass, array $fields, array $plan): array
    {
        if (!ResourceSettings::shouldNarrowColumns()) {
            return $with;
        }

        $relations = $this->resolvePaths($root, array_keys($plan));

        if ($relations === null) {
            return $with;
        }

        $proven = ['' => $this->proves($root, $resourceClass, $fields)];

        foreach ($plan as $path => $projection) {

            $relation = $relations[$path];

            if ($relation === null || !($proven[str_contains($path, '.') ? Str::beforeLast($path, '.') : ''] ?? false)) {
                continue;
            }

            $proven[$path] = $this->proves($relation->getRelated(), $projection->resource, $projection->fields);
            $with          = $this->narrowPath($with, $path, $projection, $relation);
        }

        return $with;
    }

    /**
     * Narrow the path's entry when it alone renders its models and its columns
     * can be proven.
     *
     * @param  array<int|string, mixed>  $with
     * @param  string  $path
     * @param  \SineMacula\ApiToolkit\Schema\ChildProjection  $projection
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @return array<int|string, mixed>
     */
    private function narrowPath(array $with, string $path, ChildProjection $projection, EloquentRelation $relation): array
    {
        if ($projection->opaque || !self::loads($with, $path)) {
            return $with;
        }

        $columns = $this->projectColumns($path, $projection, $relation, $with);

        if ($columns === null) {
            return $with;
        }

        return $this->narrowEntry($with, $path, $projection->resource, array_map($relation->getRelated()->qualifyColumn(...), $columns));
    }

    /**
     * Resolve each planned path to its relation, or null beneath a MorphTo or
     * where it does not resolve; null overall when any relation sets an
     * inverse.
     *
     * The plan lists every path after its parent.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $root
     * @param  array<int, string>  $paths
     * @return array<string, \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>|null>|null
     */
    private function resolvePaths(Model $root, array $paths): ?array
    {
        $relations = [];

        foreach ($paths as $path) {

            $owner    = str_contains($path, '.') ? ($relations[Str::beforeLast($path, '.')] ?? null)?->getRelated() : $root;
            $relation = $owner === null ? null : $this->schemaIntrospector->resolveRelation(Str::afterLast($path, '.'), $owner);

            if ($relation !== null && self::hasInverse($relation)) {
                return null;
            }

            $relations[$path] = $relation instanceof MorphTo ? null : $relation;
        }

        return $relations;
    }

    /**
     * Compute the columns the path selects, or null when it must load in full.
     *
     * @param  string  $path
     * @param  \SineMacula\ApiToolkit\Schema\ChildProjection  $projection
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @param  array<int|string, mixed>  $with
     * @return array<int, string>|null
     */
    private function projectColumns(string $path, ChildProjection $projection, EloquentRelation $relation, array $with): ?array
    {
        $relatedKeys = $this->schemaIntrospector->relatedKeysFor($relation);

        if ($relatedKeys === null) {
            return null;
        }

        $related    = $relation->getRelated();
        $eagerLoads = [...$this->pathsBeneath($with, $path), ...$relation->getQuery()->getEagerLoads()];
        $safety     = $this->safetySetDeriver->derive($related, $eagerLoads, []);

        if (!$safety->shouldNarrow()) {
            $this->logFallback($related, $projection, $path, ['relation' => $safety->reason()]);

            return null;
        }

        $map      = FieldColumnMapper::for($projection->resource);
        $decision = (new ColumnNarrower)->decide($map, $projection->fields, [...$safety->columns(), ...$relatedKeys], $related, $this->schemaIntrospector->getColumns($related));

        if (!$decision->shouldNarrow()) {
            $this->logFallback($related, $projection, $path, ['field' => $decision->reason()]);
        }

        return $decision->shouldNarrow() ? $decision->columns() : null;
    }

    /**
     * Return the eager-load paths beneath the given one, relative to it.
     *
     * @param  array<int|string, mixed>  $with
     * @param  string  $path
     * @return list<string>
     */
    private function pathsBeneath(array $with, string $path): array
    {
        $beneath = [];

        foreach ($with as $key => $value) {

            $entry = is_string($key) ? $key : $value;

            if (!is_string($entry) || !str_starts_with($entry, $path . '.')) {
                continue;
            }

            $beneath[] = substr($entry, strlen($path) + 1);
        }

        return $beneath;
    }

    /**
     * Replace the path's entry with one that narrows after its constraint.
     *
     * @param  array<int|string, mixed>  $with
     * @param  string  $path
     * @param  string  $resource
     * @param  array<int, string>  $columns
     * @return array<int|string, mixed>
     */
    private function narrowEntry(array $with, string $path, string $resource, array $columns): array
    {
        $constraint = ($with[$path] ?? null) instanceof \Closure ? $with[$path] : null;

        $with = array_filter($with, static fn (mixed $value, int|string $key): bool => is_string($key) || $value !== $path, ARRAY_FILTER_USE_BOTH);

        $with[$path] = static function (EloquentRelation $relation) use ($constraint, $path, $resource, $columns): void {

            if ($constraint !== null) {
                $constraint($relation);
            }

            if (self::hasInverse($relation)) {

                $message = 'The %s relation rendered by %s sets an inverse in its constraint, which column narrowing cannot keep; declare chaperone() on the relation';

                throw new \LogicException(sprintf($message, $path, $resource));
            }

            ColumnProjection::apply($relation->getQuery()->getQuery(), $columns);
        };

        return $with;
    }

    /**
     * Log why the path loads every column.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $related
     * @param  \SineMacula\ApiToolkit\Schema\ChildProjection  $projection
     * @param  string  $path
     * @param  array<string, string|null>  $cause
     * @return void
     */
    private function logFallback(Model $related, ChildProjection $projection, string $path, array $cause): void
    {
        Log::debug('Column narrowing fell back to a full select', ['model' => $related::class, 'resource' => $projection->resource, 'path' => $path, ...$cause]);
    }

    /**
     * Determine whether every field the level renders reads only columns the
     * select can prove.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @return bool
     */
    private function proves(Model $model, string $resourceClass, array $fields): bool
    {
        return (new ColumnNarrower)->decide(FieldColumnMapper::for($resourceClass), $fields, [], $model, $this->schemaIntrospector->getColumns($model))->shouldNarrow();
    }

    /**
     * Determine whether the map loads the path itself, plain or keyed.
     *
     * @param  array<int|string, mixed>  $with
     * @param  string  $path
     * @return bool
     */
    private static function loads(array $with, string $path): bool
    {
        return array_key_exists($path, $with) || in_array($path, $with, true);
    }

    /**
     * Determine whether the relation sets an inverse on its results.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @return bool
     */
    private static function hasInverse(EloquentRelation $relation): bool
    {
        return $relation instanceof HasOneOrMany && $relation->getInverseRelationship() !== null;
    }
}
