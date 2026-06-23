<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;

/**
 * Per-query enforcement policy for a resource's declared query surface.
 *
 * Bridges the declared filterable/sortable/traversable sets (compiled from the
 * schema DSL) to the filter and sort enforcement gates. Under the allowlist
 * posture a key on the root resource is permitted only when the resource
 * declared it; under the blocklist posture the legacy shape-derived predicates
 * apply. An undeclared root key is rejected with a named ValidationException
 * when fail-closed, or dropped when fail-quiet. A resource with no declared
 * surface (e.g. a model with no mapped resource) yields empty sets, so the
 * allowlist posture rejects every root key - secure by default.
 *
 * Keys that target a nested/related model (within a declared-traversable
 * relation) fall back to the legacy searchable predicate on that related model:
 * the top-level relation allowlist is the P0 worker, and per-related-resource
 * nested-column granularity is deferred (BL-20 P2). The relation gate still
 * governs which relations may be traversed at all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QuerySurface
{
    /** @var string The opt-in declared-intent posture. */
    public const string POSTURE_ALLOWLIST = 'allowlist';

    /** @var string The opt-out shape-derived posture. */
    public const string POSTURE_BLOCKLIST = 'blocklist';

    /**
     * Create a new query surface bound to the root query's model.
     *
     * @param  array<int, string>  $filterableColumns
     * @param  array<int, string>  $sortableColumns
     * @param  array<int, string>  $traversableRelations
     * @param  string  $posture
     * @param  bool  $rejectUndeclared
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     * @param  \Illuminate\Database\Eloquent\Model  $rootModel
     */
    public function __construct(

        /** Declared filterable columns for the root resource. */
        private array $filterableColumns,

        /** Declared sortable columns for the root resource. */
        private array $sortableColumns,

        /** Declared traversable relations for the root resource. */
        private array $traversableRelations,

        /** The active enforcement posture (allowlist or blocklist). */
        private string $posture,

        /** Whether to reject undeclared root keys (fail-closed). */
        private bool $rejectUndeclared,

        /** Schema introspection provider for the root model. */
        private SchemaIntrospectionProvider $introspector,

        /** The root query's Eloquent model instance. */
        private Model $rootModel,
    ) {}

    /**
     * Guard a filter column on the given model, returning whether it may be
     * applied and throwing on an undeclared root key under the fail-closed
     * allowlist posture.
     *
     * @param  string  $column
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardFilter(string $column, Model $model): bool
    {
        return $this->guard($this->permitsFilter($column, $model), 'filters', $column, $model);
    }

    /**
     * Guard a sort column on the given model.
     *
     * @param  string  $column
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardSort(string $column, Model $model): bool
    {
        return $this->guard($this->permitsSort($column, $model), 'order', $column, $model);
    }

    /**
     * Guard a relation traversal key on the given model.
     *
     * @param  string  $relation
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardRelation(string $relation, Model $model): bool
    {
        return $this->guard($this->permitsRelation($relation, $model), 'filters', $relation, $model);
    }

    /**
     * Determine whether the column is filterable on the given model.
     *
     * @param  string  $column
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    private function permitsFilter(string $column, Model $model): bool
    {
        return $this->governsRoot($model)
            ? in_array($column, $this->filterableColumns, true)
            : $this->introspector->isSearchable($model, $column);
    }

    /**
     * Determine whether the column is sortable on the given model.
     *
     * @param  string  $column
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    private function permitsSort(string $column, Model $model): bool
    {
        return $this->governsRoot($model)
            ? in_array($column, $this->sortableColumns, true)
            : $this->introspector->isSearchable($model, $column);
    }

    /**
     * Determine whether the relation is traversable on the given model.
     *
     * @param  string  $relation
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    private function permitsRelation(string $relation, Model $model): bool
    {
        return $this->governsRoot($model)
            ? in_array($relation, $this->traversableRelations, true)
            : $this->introspector->isRelation($relation, $model);
    }

    /**
     * Determine whether the declared allowlist governs the given model - i.e.
     * the allowlist posture is in force and the model is the root model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    private function governsRoot(Model $model): bool
    {
        return $this->posture === self::POSTURE_ALLOWLIST && $model::class === $this->rootModel::class;
    }

    /**
     * Resolve a permission result into an apply/skip decision, rejecting an
     * undeclared root key when fail-closed under the allowlist posture.
     *
     * @param  bool  $permitted
     * @param  string  $parameter
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function guard(bool $permitted, string $parameter, string $key, Model $model): bool
    {
        if ($permitted) {
            return true;
        }

        if ($this->rejectUndeclared && $this->governsRoot($model)) {
            throw ValidationException::withMessages([$parameter . '.' . $key => sprintf('The "%s" key is not a permitted query parameter for this resource.', $key)]);
        }

        return false;
    }
}
