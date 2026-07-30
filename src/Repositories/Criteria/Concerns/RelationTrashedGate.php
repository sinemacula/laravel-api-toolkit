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
 * Cascades soft-delete visibility to eager-loaded relations.
 *
 * Decorates a with()-ready eager-load map so each relation load applies the
 * requested trashed scope only when that relation's own leaf model uses
 * SoftDeletes and its mapped resource opts in. The gate is closed by default
 * and resolved independently per relation, so a relation whose resource has not
 * opted in never surfaces trashed rows even when the same request opened the
 * root or a sibling relation.
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
     * Normalise a raw eager-load entry into a path and optional constraint.
     *
     * @param  int|string  $key
     * @param  mixed  $value
     * @return array{0: string, 1: (\Closure(mixed): void)|null}
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
     * @param  array<int|string, mixed>  $decorated
     * @param  string  $path
     * @param  (\Closure(mixed): void)|null  $constraint
     * @param  \Illuminate\Database\Eloquent\Model  $root
     * @param  \SineMacula\ApiToolkit\Enums\TrashedState  $state
     * @return array<int|string, mixed>
     */
    private function addEntry(array $decorated, string $path, ?\Closure $constraint, Model $root, TrashedState $state): array
    {
        $leaf = $this->resolveLeaf($root, $path);

        if ($leaf === null || !$this->isGateOpen($leaf)) {
            return $this->appendDefault($decorated, $path, $constraint);
        }

        $decorated[$path] = $this->scopeClosure($constraint, $state);

        return $decorated;
    }

    /**
     * Append a relation with its default live-only scope, preserving any
     * existing constraint.
     *
     * @param  array<int|string, mixed>  $decorated
     * @param  string  $path
     * @param  (\Closure(mixed): void)|null  $constraint
     * @return array<int|string, mixed>
     */
    private function appendDefault(array $decorated, string $path, ?\Closure $constraint): array
    {
        if ($constraint === null) {
            $decorated[] = $path;
        } else {
            $decorated[$path] = $constraint;
        }

        return $decorated;
    }

    /**
     * Walk the dot-path from the root model to resolve the leaf related model,
     * returning null when any segment cannot be resolved.
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
     * Determine whether the leaf model uses SoftDeletes and its mapped resource
     * opts in to trashed visibility for the current request.
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
     * Build a constraint closure that applies the gated scope on top of any
     * existing constraint.
     *
     * @param  (\Closure(mixed): void)|null  $existing
     * @param  \SineMacula\ApiToolkit\Enums\TrashedState  $state
     * @return \Closure(mixed): void
     */
    private function scopeClosure(?\Closure $existing, TrashedState $state): \Closure
    {
        return function ($query) use ($existing, $state): void {

            if ($existing !== null) {
                $existing($query);
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

        if ($state === TrashedState::ONLY) {
            $builder->onlyTrashed(); // @phpstan-ignore method.notFound

            return;
        }

        $builder->withTrashed(); // @phpstan-ignore method.notFound
    }
}
