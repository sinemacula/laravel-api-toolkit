<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
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
final readonly class PageOrder
{
    /**
     * Create a new page order.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral  $literal
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     */
    public function __construct(

        /** Checks each cursor boundary against its column's storage type */
        private ColumnLiteral $literal,

        /** Reports whether the model's table holds its key column */
        private SchemaIntrospectionProvider $introspector,
    ) {}

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
     * Only the token's shape, its columns and each boundary's type are checked:
     * it carries values, not provenance.
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
        $model = $query->getModel();

        $this->guardKeyed($query->getQuery(), $model);

        if ($token === '') {
            return null;
        }

        $cursor     = Cursor::fromEncoded($token);
        $parameters = $cursor?->toArray() ?? [];

        if (!is_bool($parameters['_pointsToNextItems'] ?? null)) {
            throw Rejection::at('cursor', 'cursor.malformed');
        }

        $columns = array_unique($this->seekColumns($query->getQuery(), $model));

        if (count($parameters) !== count($columns) + 1) {
            throw Rejection::at('cursor', 'cursor.mismatch');
        }

        foreach ($columns as $column) {

            $boundary = $parameters[$column] ?? null;

            if (!is_scalar($boundary) || !$this->literal->fitsBoundary($model, Str::after($column, $model->getTable() . '.'), $boundary)) {
                throw Rejection::at('cursor', 'cursor.mismatch');
            }
        }

        return $cursor;
    }

    /**
     * Bound the leading order column at the cursor, so the read seeks from it.
     *
     * The bound is implied by the seek predicate, but PostgreSQL reads that
     * predicate's disjunction from the start of the index rather than as a
     * range.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  \Illuminate\Pagination\Cursor|null  $cursor
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function seek(Builder $query, ?Cursor $cursor): Builder
    {
        $base   = $query->getQuery();
        $column = $this->boundableColumn($base, $query->getModel());

        if ($cursor === null || $column === null) {
            return $query;
        }

        $forward = (($base->orders[0]['direction'] ?? null) === 'asc') === $cursor->pointsToNextItems();

        $base->where($column, $forward ? '>=' : '<=', $cursor->parameter($column));

        return $query;
    }

    /**
     * Resolve the leading order column a seek gains from bounding, or null.
     *
     * A lone ordered column already seeks by range. Only a column of the
     * queried table is bound, since an alias is not a column a where clause can
     * name.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     */
    private function boundableColumn(QueryBuilder $base, Model $model): ?string
    {
        $orders = $base->orders        ?? [];
        $column = $orders[0]['column'] ?? null;

        if (count($orders) < 2 || !empty($base->unions) || !is_string($column)) {
            return null;
        }

        return str_starts_with($column, $model->getTable() . '.') ? $column : null;
    }

    /**
     * Refuse a cursor over a table without its key column, which only a grouped
     * and ordered read can seek without.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function guardKeyed(QueryBuilder $base, Model $model): void
    {
        $groupOrdered = !empty($base->groups) && $this->seekColumns($base, $model) !== [$model->getQualifiedKeyName()];

        if (!$groupOrdered && !$this->storesKey($model)) {
            throw Rejection::at('pagination', 'cursor.keyless');
        }
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

        $columns = empty($base->groups) ? $this->keyTiebreaker($base, $model) : $this->groupTiebreakers($base, $model);

        foreach ($columns as $column) {
            if (!$this->projects($base, $model, $column)) {
                return [];
            }
        }

        return $columns;
    }

    /**
     * Resolve the model key as the tiebreaker, unless the query cannot take it
     * or the table has no such column.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    private function keyTiebreaker(QueryBuilder $base, Model $model): array
    {
        $ordered = $this->columnsOf($base->orders ?? []);
        $keyed   = in_array($model->getKeyName(), $ordered, true) || in_array($model->getQualifiedKeyName(), $ordered, true);

        if ($keyed || !empty($base->havings) || !$this->storesKey($model)) {
            return [];
        }

        return [$model->getQualifiedKeyName()];
    }

    /**
     * Determine whether the model's table holds its key column, assuming so
     * where the catalogue cannot tell.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    private function storesKey(Model $model): bool
    {
        $columns = $this->introspector->getColumnDefinitions($model);

        return $columns === [] || array_key_exists($model->getKeyName(), $columns);
    }

    /**
     * Resolve the unordered group columns, or none if a group is an expression.
     *
     * An order on the qualified name covers a bare group column, but not the
     * reverse, since a bare order may name a select alias.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    private function groupTiebreakers(QueryBuilder $base, Model $model): array
    {
        $groups = $base->groups ?? [];

        if (array_filter($groups, is_string(...)) !== $groups) {
            return [];
        }

        $ordered = $this->columnsOf($base->orders ?? []);

        return array_filter($groups, static fn (string $group): bool => array_intersect([$group, $model->qualifyColumn($group)], $ordered) === []);
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
        $names     = ['*', Str::beforeLast($qualified, '.') . '.*', $qualified, Str::after($qualified, $model->getTable() . '.')];

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
