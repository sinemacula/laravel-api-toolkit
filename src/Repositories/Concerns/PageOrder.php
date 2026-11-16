<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Validation\Rejection;

/**
 * Gives a paginated read a total order, and checks a cursor against it.
 *
 * Without a unique final key, rows tied on the ordered columns repeat or vanish
 * between pages, and a cursor seeking past a tied boundary skips them.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PageOrder
{
    /**
     * Append the columns that make the query's order unique.
     *
     * The model key follows the last direction, so a (column, key) index still
     * serves the whole order in one scan. A grouped query takes its plain group
     * columns instead, since the key is neither grouped nor aggregated there.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function complete(Builder $query): Builder
    {
        $base      = $query->getQuery();
        $direction = $this->lastDirection($base);

        foreach ($this->tiebreakers($base, $query->getModel()) as $column) {
            $base->orderBy($column, $direction);
        }

        return $query;
    }

    /**
     * Decode the cursor token, refusing one the query's order cannot seek by.
     *
     * Only the token's shape is checked: it carries values, not provenance.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $token
     * @return \Illuminate\Pagination\Cursor|null
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    public function cursor(Builder $query, string $token): ?Cursor
    {
        if ($token === '') {
            return null;
        }

        $cursor     = Cursor::fromEncoded($token);
        $parameters = $cursor?->toArray() ?? [];

        if (!is_bool($parameters['_pointsToNextItems'] ?? null)) {
            throw Rejection::at('cursor', 'cursor.malformed');
        }

        foreach ($this->seekColumns($query->getQuery(), $query->getModel()) as $column) {
            if (!is_scalar($parameters[$column] ?? null)) {
                throw Rejection::at('cursor', 'cursor.mismatch');
            }
        }

        return $cursor;
    }

    /**
     * Resolve the columns to append, or none where no safe tiebreaker exists.
     *
     * A DISTINCT ON query must lead its order with the distinct columns, and a
     * SELECT DISTINCT may only be ordered by columns it selects.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    private function tiebreakers(QueryBuilder $base, Model $model): array
    {
        if (!empty($base->unions) || is_array($base->distinct)) {
            return [];
        }

        $columns = empty($base->groups) ? $this->keyTiebreaker($base, $model) : $this->groupTiebreakers($base);

        foreach ($columns as $column) {
            if (!$this->projects($base, $model, $column)) {
                return [];
            }
        }

        return $columns;
    }

    /**
     * Resolve the model key as the tiebreaker, unless the query cannot take it.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    private function keyTiebreaker(QueryBuilder $base, Model $model): array
    {
        $ordered = $this->columnsOf($base->orders ?? []);
        $keyed   = in_array($model->getKeyName(), $ordered, true) || in_array($model->getQualifiedKeyName(), $ordered, true);

        if ($keyed || !empty($base->havings)) {
            return [];
        }

        return [$model->getQualifiedKeyName()];
    }

    /**
     * Resolve the unordered group columns, or none if a group is an expression.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @return array<int, string>
     */
    private function groupTiebreakers(QueryBuilder $base): array
    {
        $groups = $base->groups ?? [];

        if (array_filter($groups, is_string(...)) !== $groups) {
            return [];
        }

        return array_diff($groups, $this->columnsOf($base->orders ?? []));
    }

    /**
     * Determine whether the query selects the column, as a distinct query must.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @return bool
     */
    private function projects(QueryBuilder $base, Model $model, string $column): bool
    {
        if ($base->distinct === false || $base->columns === null) {
            return true;
        }

        $qualified = $model->qualifyColumn($column);
        $names     = ['*', $model->getTable() . '.*', $qualified, Str::after($qualified, $model->getTable() . '.')];

        return array_filter($base->columns, static fn (mixed $selected): bool => in_array($selected, $names, true)) !== [];
    }

    /**
     * Resolve the direction of the last directional order, or ascending.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @return 'asc'|'desc'
     */
    private function lastDirection(QueryBuilder $base): string
    {
        $directions = array_column($base->orders ?? [], 'direction');

        return end($directions) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Resolve the columns a cursor must carry a value for to seek the page.
     *
     * Mirrors the paginator, which orders an unordered query by the key.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    private function seekColumns(QueryBuilder $base, Model $model): array
    {
        $orders = empty($base->unionOrders) ? ($base->orders ?? []) : $base->unionOrders;

        return $orders === [] ? [$model->getQualifiedKeyName()] : $this->columnsOf($orders);
    }

    /**
     * Resolve the plain column names among the given orders.
     *
     * Raw orders carry no column, and expression columns cannot be named.
     *
     * @param  array<int, array<string, mixed>>  $orders
     * @return array<int, string>
     */
    private function columnsOf(array $orders): array
    {
        return array_filter(array_column($orders, 'column'), is_string(...));
    }
}
