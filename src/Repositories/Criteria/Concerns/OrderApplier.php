<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;

/**
 * Applies ordering to an Eloquent query builder.
 *
 * Supports single and multiple column ordering, random ordering via the
 * `ORDER_BY_RANDOM` keyword, direction validation, and sortable-column
 * enforcement via the declared query surface.
 *
 * Random ordering is an opt-in capability: it sorts the whole table to return a
 * single page, so it applies only when it is enabled in configuration. While it
 * is disabled the keyword carries no special meaning and is gated by the
 * sortable-column enforcement like any other key, so it is rejected unless the
 * resource declares a column of that name sortable.
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
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface  $querySurface
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function apply(Builder $query, array $order, QuerySurface $querySurface): Builder
    {
        if (empty($order)) {
            return $query;
        }

        foreach ($order as $column => $direction) {

            if ($this->permitsRandomOrder($column)) {
                $query->getQuery()->inRandomOrder();
                continue;
            }

            $querySurface->guardSort($column, $query->getModel());

            if (!in_array($direction, $this->directions, true)) {
                continue;
            }

            $query->getQuery()->orderBy($column, $direction);
        }

        return $query;
    }

    /**
     * Determine whether the column names the random-ordering keyword and the
     * capability is enabled.
     *
     * @param  string  $column
     * @return bool
     */
    private function permitsRandomOrder(string $column): bool
    {
        if ($column !== self::ORDER_BY_RANDOM) {
            return false;
        }

        return (bool) Config::get('api-toolkit.repositories.allow_random_order', false);
    }
}
