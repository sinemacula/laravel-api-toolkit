<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;

/**
 * Applies ordering to an Eloquent query builder.
 *
 * Supports single and multiple column ordering, random ordering via the
 * `ORDER_BY_RANDOM` keyword, direction validation, and searchable column
 * validation via the schema introspection provider.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class OrderApplier
{
    /** @var string The column name to be used when ordering items randomly */
    public const string ORDER_BY_RANDOM = 'random';

    /** @var array<int, string> */
    private array $directions = ['asc', 'desc'];

    /**
     * Apply ordering to the query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<string, string>  $order
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function apply(Builder $query, array $order, SchemaIntrospectionProvider $schemaIntrospector): Builder
    {
        if (empty($order)) {
            return $query;
        }

        foreach ($order as $column => $direction) {

            if ($column === self::ORDER_BY_RANDOM) {
                $query->inRandomOrder();
                continue;
            }

            if ($schemaIntrospector->isSearchable($query->getModel(), $column) && in_array($direction, $this->directions, true)) {
                $query->orderBy($column, $direction);
            }
        }

        return $query;
    }
}
