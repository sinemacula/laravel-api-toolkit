<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Validation\Rejection;

/**
 * Per-query enforcement policy for one resource's declared query surface.
 *
 * Each level of a filter is governed by its own surface, and a level no
 * resource governs refuses every key.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QuerySurface
{
    /**
     * Create a new query surface for the given resource schema.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema|null  $schema
     * @param  \SineMacula\ApiToolkit\Schema\RelationTraversal  $traversal
     */
    public function __construct(

        /** The governing resource's compiled schema, or null when none governs */
        private ?CompiledSchema $schema,

        /** Resolves the resource governing each related level */
        private RelationTraversal $traversal,
    ) {}

    /**
     * Return the surface governing the level a relation descends to.
     *
     * @param  string  $relation
     * @param  \Illuminate\Database\Eloquent\Model  $related
     * @param  bool  $polymorphic
     * @return self
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function through(string $relation, Model $related, bool $polymorphic): self
    {
        $resource = $this->schema === null ? null : $this->traversal->governingResource($this->schema, $relation, $related, $polymorphic);

        return new self($resource === null ? null : SchemaCompiler::compile($resource), $this->traversal);
    }

    /**
     * Guard a filter column, rejecting an undeclared key.
     *
     * @param  string  $column
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardFilter(string $column): void
    {
        $this->guard($this->capabilityFor($column) !== null, 'filters', $column);
    }

    /**
     * Guard a filter operator against the declaring column's capability.
     *
     * Tokens the capability matrix does not govern are left to the application.
     *
     * @param  string  $column
     * @param  string  $operator
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    public function guardFilterOperator(string $column, string $operator): void
    {
        $capability = $this->capabilityFor($column);

        if ($capability === null) {
            throw Rejection::at('filters.' . $column, 'query.undeclared_key', ['key' => $column]);
        }

        if (Capability::governs($operator) && !$capability->permits($operator)) {

            $accepts = implode(', ', $capability->permittedOperators());

            throw Rejection::at('filters.' . $column . '.' . $operator, 'filters.unpermitted_operator', ['operator' => $operator, 'key' => $column, 'accepts' => $accepts]);
        }
    }

    /**
     * Guard a sort column.
     *
     * @param  string  $column
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardSort(string $column): void
    {
        $this->guard(in_array($column, $this->schema?->getSortableColumns() ?? [], true), 'order', $column);
    }

    /**
     * Guard a relation traversal key.
     *
     * @param  string  $relation
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function guardRelation(string $relation): void
    {
        $this->guard($this->isDeclaredRelation($relation), 'filters', $relation);
    }

    /**
     * Determine whether the key is a declared traversable relation.
     *
     * Lets the router skip the cached relation lookup for undeclared keys.
     *
     * @param  string  $relation
     * @return bool
     */
    public function isDeclaredRelation(string $relation): bool
    {
        return in_array($relation, $this->schema?->getTraversableRelations() ?? [], true);
    }

    /**
     * Resolve the column's declared filter capability, or null.
     *
     * @param  string  $column
     * @return \SineMacula\ApiToolkit\Enums\Capability|null
     */
    private function capabilityFor(string $column): ?Capability
    {
        return $this->schema?->getFilterableColumns()[$column] ?? null;
    }

    /**
     * Reject an unpermitted key with a named validation error.
     *
     * @param  bool  $permitted
     * @param  string  $parameter
     * @param  string  $key
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function guard(bool $permitted, string $parameter, string $key): void
    {
        if (!$permitted) {
            throw Rejection::at($parameter . '.' . $key, 'query.undeclared_key', ['key' => $key]);
        }
    }
}
