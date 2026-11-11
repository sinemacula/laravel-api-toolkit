<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Per-query enforcement policy for a resource's declared query surface.
 *
 * Undeclared keys and unmapped related models fail closed at every level.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QuerySurface
{
    /** @var string Rejection naming a key the governing resource never declared */
    private const string UNDECLARED_KEY = 'The "%s" key is not a permitted query parameter for this resource.';

    /** @var string Rejection naming an operator the declaring column's capability does not answer */
    private const string UNPERMITTED_OPERATOR = 'The "%s" operator is not permitted on the "%s" key for this resource, which accepts %s.';

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

        /** Declared filterable columns for the root resource, mapped to their capability */
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
     * Guard a filter operator against the declaring column's capability.
     *
     * Tokens the capability matrix does not govern are left to the application.
     *
     * @param  string  $column
     * @param  string  $operator
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardFilterOperator(string $column, string $operator, Model $model): void
    {
        $capability = $this->capabilityFor($column, $model);

        if ($capability === null) {
            throw $this->rejection('filters.' . $column, sprintf(self::UNDECLARED_KEY, $column));
        }

        if (Capability::governs($operator) && !$capability->permits($operator)) {

            $accepts = implode(', ', $capability->permittedOperators());

            throw $this->rejection('filters.' . $column . '.' . $operator, sprintf(self::UNPERMITTED_OPERATOR, $operator, $column, $accepts));
        }
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
     * Determine whether the key is a declared traversable relation.
     *
     * Lets the router skip the cached relation lookup for undeclared keys.
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
        return $this->capabilityFor($column, $model) !== null;
    }

    /**
     * Resolve the column's declared filter capability on the model, or null.
     *
     * @param  string  $column
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \SineMacula\ApiToolkit\Enums\Capability|null
     */
    private function capabilityFor(string $column, Model $model): ?Capability
    {
        if ($this->governsRoot($model)) {
            return $this->filterableColumns[$column] ?? null;
        }

        return $this->resolveRelatedSchema($model)?->getFilterableColumns()[$column] ?? null;
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

        $schema = $this->resolveRelatedSchema($model);

        return $schema !== null && in_array($column, $schema->getSortableColumns(), true);
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
     * Determine whether the relation is traversable on a related model.
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
     * Resolve the related model's mapped resource schema, or null if unmapped.
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
     * Determine whether the given model is the root model.
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
            throw $this->rejection($parameter . '.' . $key, sprintf(self::UNDECLARED_KEY, $key));
        }
    }

    /**
     * Build the validation error naming the rejected position and the reason.
     *
     * @param  string  $key
     * @param  string  $message
     * @return \Illuminate\Validation\ValidationException
     */
    private function rejection(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => $message]);
    }
}
