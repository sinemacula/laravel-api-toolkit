<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\TrashedState;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;

/**
 * Cascades soft-delete visibility to relation loads, aggregates and filters.
 *
 * Gated per relation, so one relation's opt-in never opens another.
 *
 * @phpstan-import-type AggregateLoad from \SineMacula\ApiToolkit\Contracts\ApiResourceInterface
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RelationTrashedGate
{
    /**
     * Create a new relation trashed gate.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @param  \Illuminate\Http\Request  $request
     * @param  array<string, mixed>  $resourceMap
     * @return void
     */
    public function __construct(

        /** Resolves the concrete relation instance to reach the leaf model. */
        private SchemaIntrospectionProvider $schemaIntrospector,

        /** Passed to each resource gate to authorise the caller. */
        private Request $request,

        /** Maps model classes to their resource classes for gate resolution. */
        private array $resourceMap,
    ) {}

    /**
     * Decorate the eager-load map so each relation applies its gated scope.
     *
     * @param  array<int|string, mixed>  $with
     * @param  \Illuminate\Database\Eloquent\Model  $root
     * @param  \SineMacula\ApiToolkit\Enums\TrashedState  $state
     * @return array<int|string, mixed>
     */
    public function decorate(array $with, Model $root, TrashedState $state): array
    {
        if ($state === TrashedState::NONE || $with === []) {
            return $with;
        }

        $decorated = [];

        foreach ($with as $key => $value) {
            [$path, $constraint] = $this->normaliseEntry($key, $value);
            $decorated           = $this->addEntry($decorated, $path, $constraint, $root, $state);
        }

        return $decorated;
    }

    /**
     * Decorate aggregate entries so each subquery applies its gated scope.
     *
     * @param  list<AggregateLoad>  $entries
     * @param  \SineMacula\ApiToolkit\Enums\TrashedState  $state
     * @return list<AggregateLoad>
     */
    public function decorateAggregates(array $entries, TrashedState $state): array
    {
        if ($state === TrashedState::NONE) {
            return $entries;
        }

        return array_map(function (array $entry) use ($state): array {

            $constraints = is_array($entry['relation']) ? $entry['relation'] : [$entry['relation'] => null];

            $entry['relation'] = array_map(fn (?\Closure $constraint): \Closure => $this->gatedConstraint($constraint, $state), $constraints);

            return $entry;
        }, $entries);
    }

    /**
     * Apply the gated scope to a relation subquery over its related model.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  \SineMacula\ApiToolkit\Enums\TrashedState  $state
     * @return void
     */
    public function constrain(Builder $query, TrashedState $state): void
    {
        if ($state === TrashedState::NONE || !$this->isGateOpen($query->getModel())) {
            return;
        }

        $this->applyScope($query, $state);
    }

    /**
     * Build an aggregate constraint running any existing one, then the gated
     * scope.
     *
     * @param  (\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void)|null  $constraint
     * @param  \SineMacula\ApiToolkit\Enums\TrashedState  $state
     * @return \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void
     */
    public function gatedConstraint(?\Closure $constraint, TrashedState $state): \Closure
    {
        return function (Builder $query) use ($constraint, $state): void {

            if ($constraint !== null) {
                $constraint($query);
            }

            $this->constrain($query, $state);
        };
    }

    /**
     * Normalise a raw eager-load entry into a path and optional constraint.
     *
     * @param  int|string  $key
     * @param  mixed  $value
     * @return array{0: string, 1: (\Closure(mixed, mixed=): void)|null}
     */
    private function normaliseEntry(int|string $key, mixed $value): array
    {
        if (is_int($key)) {
            return [is_string($value) ? $value : '', null];
        }

        return [$key, $value instanceof \Closure ? $value : null];
    }

    /**
     * Resolve the gate for a single relation and add the resulting entry.
     *
     * A constrained relation is always wrapped, so the aggregates it loads are
     * gated even where the relation itself stays live.
     *
     * @param  array<int|string, mixed>  $decorated
     * @param  string  $path
     * @param  (\Closure(mixed, mixed=): void)|null  $constraint
     * @param  \Illuminate\Database\Eloquent\Model  $root
     * @param  \SineMacula\ApiToolkit\Enums\TrashedState  $state
     * @return array<int|string, mixed>
     */
    private function addEntry(array $decorated, string $path, ?\Closure $constraint, Model $root, TrashedState $state): array
    {
        $leaf = $this->resolveLeaf($root, $path);
        $open = $leaf !== null && $this->isGateOpen($leaf);

        if (!$open && $constraint === null) {
            $decorated[] = $path;
        } else {
            $decorated[$path] = $this->scopeClosure($constraint, $state, $open);
        }

        return $decorated;
    }

    /**
     * Walk the dot-path from the root to its leaf model, or null if unresolved.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $root
     * @param  string  $path
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    private function resolveLeaf(Model $root, string $path): ?Model
    {
        $model = $root;

        foreach (explode('.', $path) as $segment) {

            $relation = $this->schemaIntrospector->resolveRelation($segment, $model);

            if ($relation === null) {
                return null;
            }

            $model = $relation->getRelated();
        }

        return $model;
    }

    /**
     * Determine whether the leaf uses SoftDeletes and its resource opts in.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $leaf
     * @return bool
     */
    private function isGateOpen(Model $leaf): bool
    {
        if (!in_array(SoftDeletes::class, class_uses_recursive($leaf), true)) {
            return false;
        }

        $resource = $this->resourceMap[$leaf::class] ?? null;

        if (!is_string($resource) || !is_subclass_of($resource, ApiResource::class)) {
            return false;
        }

        return $resource::allowsTrashed($this->request) === true;
    }

    /**
     * Build a closure running any existing constraint, then the gated scope.
     *
     * The existing constraint is handed the aggregate decorator, which the
     * planner's closures apply to the aggregates they load.
     *
     * @param  (\Closure(mixed, mixed=): void)|null  $existing
     * @param  \SineMacula\ApiToolkit\Enums\TrashedState  $state
     * @param  bool  $open
     * @return \Closure(mixed): void
     */
    private function scopeClosure(?\Closure $existing, TrashedState $state, bool $open): \Closure
    {
        return function ($query) use ($existing, $state, $open): void {

            if ($existing !== null) {
                $existing($query, function (array $entries) use ($state): array {
                    /** @var list<AggregateLoad> $entries */
                    return $this->decorateAggregates($entries, $state);
                });
            }

            if (!$open) {
                return;
            }

            $this->applyScope($query, $state);
        };
    }

    /**
     * Apply the requested trashed scope to the relation's underlying builder.
     *
     * @param  mixed  $query
     * @param  \SineMacula\ApiToolkit\Enums\TrashedState  $state
     * @return void
     */
    private function applyScope(mixed $query, TrashedState $state): void
    {
        $builder = $query instanceof EloquentRelation ? $query->getQuery() : $query;

        if (!($builder instanceof Builder)) {
            return;
        }

        $builder->withTrashed(); // @phpstan-ignore method.notFound

        if ($state !== TrashedState::ONLY) {
            return;
        }

        // Its own scope, so no OR or relation merge can strip it
        $builder->withGlobalScope(self::class, static function (Builder $query): void {
            $query->whereNotNull($query->getModel()->getQualifiedDeletedAtColumn()); // @phpstan-ignore method.notFound
        });
    }
}
