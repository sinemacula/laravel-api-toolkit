<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;

/**
 * Per-query enforcement policy for a resource's declared query surface.
 *
 * Bridges the declared filterable/sortable/traversable sets (compiled from the
 * schema DSL) to the filter and sort enforcement gates. Under the allowlist
 * posture a key is permitted only when the resource declared it; under the
 * blocklist posture the legacy shape-derived predicates apply. An undeclared
 * key is rejected with a named ValidationException when fail-closed, or dropped
 * when fail-quiet. A resource with no declared surface (e.g. a model with no
 * mapped resource) yields empty sets, so the allowlist posture rejects every
 * key - secure by default.
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
     * Create a new query surface bound to a query's model.
     *
     * @param  array<int, string>  $filterableColumns
     * @param  array<int, string>  $sortableColumns
     * @param  array<int, string>  $traversableRelations
     * @param  string  $posture
     * @param  bool  $rejectUndeclared
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     * @param  \Illuminate\Database\Eloquent\Model  $model
     */
    public function __construct(
        private array $filterableColumns,
        private array $sortableColumns,
        private array $traversableRelations,
        private string $posture,
        private bool $rejectUndeclared,
        private SchemaIntrospectionProvider $introspector,
        private Model $model,
    ) {}

    /**
     * Guard a filter column, returning whether it may be applied and throwing
     * on an undeclared key under the fail-closed allowlist posture.
     *
     * @param  string  $column
     * @return bool
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardFilter(string $column): bool
    {
        return $this->guard($this->permitsFilter($column), 'filters', $column);
    }

    /**
     * Guard a sort column.
     *
     * @param  string  $column
     * @return bool
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardSort(string $column): bool
    {
        return $this->guard($this->permitsSort($column), 'order', $column);
    }

    /**
     * Guard a relation traversal key.
     *
     * @param  string  $relation
     * @return bool
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardRelation(string $relation): bool
    {
        return $this->guard($this->permitsRelation($relation), 'filters', $relation);
    }

    /**
     * Determine whether the column is filterable under the current posture.
     *
     * @param  string  $column
     * @return bool
     */
    public function permitsFilter(string $column): bool
    {
        return $this->isAllowlist()
            ? in_array($column, $this->filterableColumns, true)
            : $this->introspector->isSearchable($this->model, $column);
    }

    /**
     * Determine whether the column is sortable under the current posture.
     *
     * @param  string  $column
     * @return bool
     */
    public function permitsSort(string $column): bool
    {
        return $this->isAllowlist()
            ? in_array($column, $this->sortableColumns, true)
            : $this->introspector->isSearchable($this->model, $column);
    }

    /**
     * Determine whether the relation is traversable under the current posture.
     *
     * @param  string  $relation
     * @return bool
     */
    public function permitsRelation(string $relation): bool
    {
        return $this->isAllowlist()
            ? in_array($relation, $this->traversableRelations, true)
            : $this->introspector->isRelation($relation, $this->model);
    }

    /**
     * Determine whether the allowlist posture is in force.
     *
     * @return bool
     */
    private function isAllowlist(): bool
    {
        return $this->posture === self::POSTURE_ALLOWLIST;
    }

    /**
     * Resolve a permission result into an apply/skip decision, rejecting an
     * undeclared key when fail-closed under the allowlist posture.
     *
     * @param  bool  $permitted
     * @param  string  $parameter
     * @param  string  $key
     * @return bool
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function guard(bool $permitted, string $parameter, string $key): bool
    {
        if ($permitted) {
            return true;
        }

        if ($this->isAllowlist() && $this->rejectUndeclared) {
            throw ValidationException::withMessages([$parameter . '.' . $key => sprintf('The "%s" key is not a permitted query parameter for this resource.', $key)]);
        }

        return false;
    }
}
