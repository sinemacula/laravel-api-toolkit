<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Per-query enforcement policy for a resource's declared query surface.
 *
 * Bridges the declared filterable/sortable/traversable sets (compiled from the
 * schema DSL) to the filter and sort enforcement gates. A key on the root
 * resource is permitted only when the resource declared it, and an undeclared
 * key is rejected with a named ValidationException. A resource with no declared
 * surface (e.g. a model with no mapped resource) yields empty sets, so every
 * root key is rejected - secure by default.
 *
 * Keys that target a nested/related model (within a declared-traversable
 * relation) are gated against that related model's resource schema. When no
 * mapped resource exists for a related model the gate fails closed (rejects),
 * matching the root secure-by-default behaviour. Relation traversal is gated
 * the same way at every level: the root relation against the declared
 * traversable set, and each onward relation against the related resource's
 * declared traversable set, failing closed when the related model has no mapped
 * resource. Traversal depth is therefore bounded by declarations rather than a
 * fixed limit - a chain ends at the first undeclared or unmapped hop.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QuerySurface
{
    /**
     * Create a new query surface bound to the root query's model.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Enums\Capability>  $filterableColumns
     * @param  array<int, string>  $sortableColumns
     * @param  array<int, string>  $traversableRelations
     * @param  \Illuminate\Database\Eloquent\Model  $rootModel
     * @param  array<string, string>  $resourceMap
     */
    public function __construct(

        /** Declared filterable columns for the root resource, mapped to their capability. */
        private array $filterableColumns,

        /** Declared sortable columns for the root resource. */
        private array $sortableColumns,

        /** Declared traversable relations for the root resource. */
        private array $traversableRelations,

        /** The root query's Eloquent model instance. */
        private Model $rootModel,

        /** Resource map used to resolve related model resource classes. */
        private array $resourceMap = [],
    ) {}

    /**
     * Guard a filter column on the given model, rejecting an undeclared key.
     *
     * @param  string  $column
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardFilter(string $column, Model $model): void
    {
        $this->guard($this->permitsFilter($column, $model), 'filters', $column);
    }

    /**
     * Guard a sort column on the given model.
     *
     * @param  string  $column
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardSort(string $column, Model $model): void
    {
        $this->guard($this->permitsSort($column, $model), 'order', $column);
    }

    /**
     * Guard a relation traversal key on the given model.
     *
     * @param  string  $relation
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardRelation(string $relation, Model $model): void
    {
        $this->guard($this->permitsRelation($relation, $model), 'filters', $relation);
    }

    /**
     * Determine whether the key is a declared traversable relation on the model
     * without throwing.
     *
     * The filter router calls this to route a key to the relation filter, so an
     * undeclared key is gated as a filter column instead and never reaches the
     * cached relation lookup, which therefore cannot grow its key space per
     * distinct sprayed key.
     *
     * @param  string  $relation
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    public function isDeclaredRelation(string $relation, Model $model): bool
    {
        return $this->permitsRelation($relation, $model);
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
            ? array_key_exists($column, $this->filterableColumns)
            : $this->permitsRelatedColumn($column, $model, true);
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
            : $this->permitsRelatedColumn($column, $model, false);
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
            : $this->permitsRelatedRelation($relation, $model);
    }

    /**
     * Determine whether the relation is traversable on a related (non-root)
     * model by checking the related resource's declared traversable set.
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
     * by checking the related resource's declared filterable or sortable set.
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

        return $filterable
            ? array_key_exists($column, $schema->getFilterableColumns())
            : in_array($column, $schema->getSortableColumns(), true);
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
     * the model is the root model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    private function governsRoot(Model $model): bool
    {
        return $model::class === $this->rootModel::class;
    }

    /**
     * Reject an unpermitted key with a named validation error.
     *
     * @param  bool  $permitted
     * @param  string  $parameter
     * @param  string  $key
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function guard(bool $permitted, string $parameter, string $key): void
    {
        if (!$permitted) {
            throw ValidationException::withMessages([$parameter . '.' . $key => sprintf('The "%s" key is not a permitted query parameter for this resource.', $key)]);
        }
    }
}
