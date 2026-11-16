<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Validation\Rejection;

/**
 * Applies ordering to an Eloquent query builder.
 *
 * Random ordering sorts the whole table, so it is opt-in via configuration. A
 * cursor cannot seek past a random or nullable key, so both are refused there.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class OrderApplier
{
    /** @var string The column name to be used when ordering items randomly */
    public const string ORDER_BY_RANDOM = 'random';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @return void
     */
    public function __construct(

        /** Reports whether an ordered column admits null */
        private SchemaIntrospectionProvider $schemaIntrospector,
    ) {}

    /**
     * Apply ordering to the query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<string, 'asc'|'desc'>  $order
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

        $cursorPaginated = ApiQuery::isCursorPaginated();

        foreach ($order as $column => $direction) {

            if ($this->permitsRandomOrder($column)) {
                $this->guardRandomOrder($cursorPaginated);
                $query->getQuery()->inRandomOrder();
                continue;
            }

            $querySurface->guardSort($column, $query->getModel());

            if ($cursorPaginated) {
                $this->guardCursorKey($column, $query->getModel());
            }

            $query->getQuery()->orderBy($column, $direction);
        }

        return $query;
    }

    /**
     * Refuse random order on a cursor-paginated read, which cannot seek by it.
     *
     * @param  bool  $cursorPaginated
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function guardRandomOrder(bool $cursorPaginated): void
    {
        if ($cursorPaginated) {
            throw Rejection::at('order.' . self::ORDER_BY_RANDOM, 'order.random_cursor');
        }
    }

    /**
     * Refuse a cursor key the catalogue reports as admitting null.
     *
     * A seek past a null boundary drops rows, and a column the catalogue does
     * not list passes, as schema validation lets it.
     *
     * @param  string  $column
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function guardCursorKey(string $column, Model $model): void
    {
        $definition = $this->schemaIntrospector->getColumnDefinitions($model)[$column] ?? null;

        if ($definition?->nullable === true) {
            throw Rejection::at('order.' . $column, 'order.nullable_cursor', ['key' => $column]);
        }
    }

    /**
     * Determine whether the column is the enabled random-ordering keyword.
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
