<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;

/**
 * Narrows a query's select to a column list without discarding the selects
 * others added.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ColumnProjection
{
    /**
     * Select the columns in place of the query's wildcard, leaving a select a
     * caller chose untouched.
     *
     * Expressions beside the wildcard, such as aggregate subselects, keep their
     * position and bindings.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<int, string>  $columns
     * @return bool
     */
    public static function apply(Builder $query, array $columns): bool
    {
        if ($query->columns === null) {
            $query->select($columns);

            return true;
        }

        $chosen = array_filter($query->columns, static fn (mixed $column): bool => !$column instanceof Expression);

        if (count($chosen) !== 1 || !self::isWildcard($query, reset($chosen))) {
            return false;
        }

        $query->columns = array_merge(...array_map(static fn (mixed $column): array => $column instanceof Expression ? [$column] : $columns, $query->columns));

        return true;
    }

    /**
     * Determine whether the column selects every column of the query's table.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $column
     * @return bool
     */
    private static function isWildcard(Builder $query, string $column): bool
    {
        return $column === '*' || (is_string($query->from) && $column === $query->from . '.*');
    }
}
