<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\Versioned;

/**
 * Derives the columns a narrowed query must always retain.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SafetySetDeriver
{
    /**
     * Create a new safety-set deriver instance.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     */
    public function __construct(

        /** The schema introspection port for column and relation metadata. */
        private SchemaIntrospectionProvider $schemaIntrospector,
    ) {}

    /**
     * Derive the per-model safety-set columns for a narrowed query, or fall
     * back naming the eager-loaded relation whose keys cannot be proven.
     *
     * A relation with an inverse sets the parent on its results after their
     * nested loads, replacing those with the narrowed parent.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<int|string, mixed>  $eagerLoads
     * @param  array<int, string>  $orderColumns
     * @return \SineMacula\ApiToolkit\Schema\NarrowingDecision
     */
    public function derive(Model $model, array $eagerLoads, array $orderColumns): NarrowingDecision
    {
        $columns = [$model->getKeyName()];

        $softDeleteColumn = $this->schemaIntrospector->getDeletedAtColumn($model);

        if ($softDeleteColumn !== null) {
            $columns[] = $softDeleteColumn;
        }

        if ($model instanceof Versioned) {
            $columns[] = $model->getVersionColumn();
        }

        foreach ($this->relationNames($eagerLoads) as $name) {

            $relation = $this->schemaIntrospector->resolveRelation($name, $model);
            $keys     = $relation === null ? null : $this->schemaIntrospector->parentKeysFor($relation);

            if ($keys === null || ($relation instanceof HasOneOrMany && $relation->getInverseRelationship() !== null)) {
                return NarrowingDecision::fallback($name);
            }

            $columns = [...$columns, ...$keys];
        }

        return NarrowingDecision::narrow($this->intersectWithRealColumns($model, [...$columns, ...$orderColumns]));
    }

    /**
     * Extract the model's own relation names from an eager-load map.
     *
     * Scoped relations are keyed by path; plain ones are list values, and a
     * `relation:columns` entry names its relation before the colon.
     *
     * @param  array<int|string, mixed>  $eagerLoads
     * @return array<int, string>
     */
    private function relationNames(array $eagerLoads): array
    {
        $names = [];

        foreach ($eagerLoads as $key => $value) {

            $path = is_string($key) ? $key : $value;

            if (!is_string($path)) {
                continue;
            }

            $names[] = Str::before(Str::before($path, '.'), ':');
        }

        return array_unique($names);
    }

    /**
     * Keep only the model's real table columns, de-duplicated in order.
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
