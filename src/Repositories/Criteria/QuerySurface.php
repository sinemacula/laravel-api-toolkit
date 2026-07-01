<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

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
 * relation) are gated against that related model's resource schema under the
 * allowlist posture. When no mapped resource exists for a related model the
 * gate fails closed (rejects), matching the root secure-by-default behaviour.
 * Under the blocklist posture the legacy searchable predicate still applies for
 * related models. Relation traversal is gated the same way at every level: the
 * root relation against the declared traversable set, and each onward relation
 * against the related resource's declared traversable set, failing closed when
 * the related model has no mapped resource. Traversal depth is therefore
 * bounded by declarations rather than a fixed limit - a chain ends at the
 * first undeclared or unmapped hop.
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
     * @param  array<string, string>  $resourceMap
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

        /** Resource map used to resolve related model resource classes. */
        private array $resourceMap = [],
    ) {}

    /**
     * Guard a filter column on the given model, returning whether it may be
     * applied and throwing on an undeclared key under the fail-closed allowlist
     * posture.
     *
     * @param  string  $column
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardFilter(string $column, Model $model): bool
    {
        return $this->guard(
            $this->permitsFilter($column, $model),
            'filters',
            $column,
            $model,
            $this->posture === self::POSTURE_ALLOWLIST,
        );
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
        return $this->guard(
            $this->permitsSort($column, $model),
            'order',
            $column,
            $model,
            $this->posture === self::POSTURE_ALLOWLIST,
        );
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
        return $this->guard(
            $this->permitsRelation($relation, $model),
            'filters',
            $relation,
            $model,
            $this->posture === self::POSTURE_ALLOWLIST,
        );
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
        if ($this->governsRoot($model)) {
            return in_array($column, $this->filterableColumns, true);
        }

        if ($this->posture === self::POSTURE_ALLOWLIST) {
            return $this->permitsRelatedColumn($column, $model, true);
        }

        return $this->introspector->isSearchable($model, $column);
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
        if ($this->governsRoot($model)) {
            return in_array($column, $this->sortableColumns, true);
        }

        if ($this->posture === self::POSTURE_ALLOWLIST) {
            return $this->permitsRelatedColumn($column, $model, false);
        }

        return $this->introspector->isSearchable($model, $column);
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
        if ($this->governsRoot($model)) {
            return in_array($relation, $this->traversableRelations, true);
        }

        if ($this->posture === self::POSTURE_ALLOWLIST) {
            return $this->permitsRelatedRelation($relation, $model);
        }

        return $this->introspector->isRelation($relation, $model);
    }

    /**
     * Determine whether the relation is traversable on a related (non-root)
     * model under the allowlist posture by checking the related resource's
     * declared traversable set.
     *
     * When no resource is mapped for the related model the gate fails closed,
     * matching the root secure-by-default behaviour.
     *
     * @param  string  $relation
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    private function permitsRelatedRelation(string $relation, Model $model): bool
    {
        $schema = $this->resolveRelatedSchema($model);

        return $schema !== null && in_array($relation, $schema->getTraversableRelations(), true);
    }

    /**
     * Determine whether the column is permitted on a related (non-root) model
     * under the allowlist posture by checking the related resource's declared
     * filterable or sortable set.
     *
     * When no resource is mapped for the related model the gate fails closed,
     * matching the root secure-by-default behaviour.
     *
     * @param  string  $column
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  bool  $filterable
     * @return bool
     */
    private function permitsRelatedColumn(string $column, Model $model, bool $filterable): bool
    {
        $schema = $this->resolveRelatedSchema($model);

        if ($schema === null) {
            return false;
        }

        $columns = $filterable ? $schema->getFilterableColumns() : $schema->getSortableColumns();

        return in_array($column, $columns, true);
    }

    /**
     * Resolve the compiled schema for a related model's mapped resource, or
     * null when the model has no mapped resource (or the mapping does not
     * implement the resource contract).
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema|null
     */
    private function resolveRelatedSchema(Model $model): ?CompiledSchema
    {
        $resourceClass = $this->resourceMap[$model::class] ?? null;

        if ($resourceClass === null || !is_subclass_of($resourceClass, ApiResourceInterface::class)) {
            return null;
        }

        return SchemaCompiler::compile($resourceClass);
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
     * Resolve a permission result into an apply/skip decision.
     *
     * Rejects with a named ValidationException when fail-closed and the active
     * posture covers the given model: root keys under allowlist, or any key
     * under allowlist when rejectForAllowlist is true.
     *
     * @param  bool  $permitted
     * @param  string  $parameter
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  bool  $rejectForAllowlist
     * @return bool
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function guard(bool $permitted, string $parameter, string $key, Model $model, bool $rejectForAllowlist = false): bool
    {
        if ($permitted) {
            return true;
        }

        if ($this->rejectUndeclared && ($this->governsRoot($model) || $rejectForAllowlist)) {
            throw ValidationException::withMessages([$parameter . '.' . $key => sprintf('The "%s" key is not a permitted query parameter for this resource.', $key)]);
        }

        return false;
    }
}
