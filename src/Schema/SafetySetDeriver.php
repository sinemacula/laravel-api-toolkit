<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;

/**
 * Derives the per-model safety set of columns a narrowed query must always
 * retain.
 *
 * Composes, at runtime, the union of primary key, soft-delete column, relation
 * parent-side keys, aliased-scalar columns, order columns, and append sources,
 * then intersects against the model's real table columns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SafetySetDeriver
{
    /**
     * Create a new safety-set deriver instance.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     */
    public function __construct(

        /** The schema introspection port for column and relation metadata. */
        private readonly SchemaIntrospectionProvider $schemaIntrospector,

    ) {}

    /**
     * Derive the per-model safety-set columns for a narrowed query.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<int, string>  $eagerLoadedRelations
     * @param  array<int, string>  $aliasedScalarColumns
     * @param  array<int, string>  $orderColumns
     * @return array<int, string>
     */
    public function derive(
        Model $model,
        array $eagerLoadedRelations,
        array $aliasedScalarColumns,
        array $orderColumns
    ): array {
        $columns = [$model->getKeyName()];

        $softDeleteColumn = $this->schemaIntrospector->getDeletedAtColumn($model);

        if ($softDeleteColumn !== null) {
            $columns[] = $softDeleteColumn;
        }

        $columns = array_merge($columns, $this->relationParentKeys($model, $eagerLoadedRelations));
        $columns = array_merge($columns, $aliasedScalarColumns);
        $columns = array_merge($columns, $orderColumns);
        $columns = array_merge($columns, $model->getAppends());

        return $this->intersectWithRealColumns($model, $columns);
    }

    /**
     * Collect the parent-side key columns for all resolvable eager-loaded
     * relations.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<int, string>  $relationKeys
     * @return array<int, string>
     */
    private function relationParentKeys(Model $model, array $relationKeys): array
    {
        $keys = [];

        foreach ($relationKeys as $relationKey) {
            $relation = $this->schemaIntrospector->resolveRelation($relationKey, $model);

            if ($relation === null) {
                continue;
            }

            $keys = array_merge($keys, $this->schemaIntrospector->parentKeysFor($relation));
        }

        return $keys;
    }

    /**
     * Intersect the accumulated column names against the model's real table
     * columns and de-duplicate, preserving order.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function intersectWithRealColumns(Model $model, array $columns): array
    {
        $real = $this->schemaIntrospector->getColumns($model);

        return array_values(array_unique(array_filter($columns, static fn (string $column): bool => in_array($column, $real, true))));
    }
}
