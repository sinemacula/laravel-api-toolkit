<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Search\SearchTerm;
use SineMacula\ApiToolkit\Validation\Rejection;

/**
 * Applies ordering to an Eloquent query builder.
 *
 * Random ordering sorts the whole table, so it is opt-in via configuration. A
 * cursor cannot seek past a random, relevance or nullable key, so each is
 * refused there.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class OrderApplier
{
    /** @var string The column name to be used when ordering items randomly */
    public const string ORDER_BY_RANDOM = 'random';

    /** @var string The key ordering search matches by how closely they match */
    public const string ORDER_BY_RELEVANCE = 'relevance';

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier  $searchApplier
     * @return void
     */
    public function __construct(

        /** Reports whether an ordered column admits null */
        private SchemaIntrospectionProvider $schemaIntrospector,

        /** Ranks the search matches the relevance key orders by */
        private SearchApplier $searchApplier,
    ) {}

    /**
     * Determine whether the order key is a keyword rather than a column.
     *
     * @param  string  $column
     * @return bool
     */
    public static function isKeyword(string $column): bool
    {
        return $column === self::ORDER_BY_RELEVANCE || self::permitsRandomOrder($column);
    }

    /**
     * Apply ordering to the query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<string, 'asc'|'desc'>  $order
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface  $querySurface
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm|null  $term
     * @param  string|null  $resourceClass
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException
     */
    public function apply(Builder $query, array $order, QuerySurface $querySurface, ?SearchTerm $term = null, ?string $resourceClass = null): Builder
    {
        if (empty($order)) {
            return $query;
        }

        $cursorPaginated = ApiQuery::isCursorPaginated();

        foreach ($order as $column => $direction) {

            if ($column === self::ORDER_BY_RELEVANCE) {
                $this->searchApplier->rank($query, $this->relevanceTerm($term, $cursorPaginated, $direction), $resourceClass);
                continue;
            }

            if (self::permitsRandomOrder($column)) {
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
     * Return the term the relevance key ranks by, refusing an order it cannot
     * honour.
     *
     * A cursor seeks by column values, and the rank is not one.
     *
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm|null  $term
     * @param  bool  $cursorPaginated
     * @param  string  $direction
     * @return \SineMacula\ApiToolkit\Search\SearchTerm
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function relevanceTerm(?SearchTerm $term, bool $cursorPaginated, string $direction): SearchTerm
    {
        $position = 'order.' . self::ORDER_BY_RELEVANCE;

        if ($term === null) {
            throw Rejection::at($position, 'order.relevance_needs_search');
        }

        if ($cursorPaginated) {
            throw Rejection::at($position, 'order.relevance_cursor');
        }

        if ($direction !== 'asc') {
            throw Rejection::at($position, 'order.relevance_direction');
        }

        return $term;
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
    private static function permitsRandomOrder(string $column): bool
    {
        if ($column !== self::ORDER_BY_RANDOM) {
            return false;
        }

        return (bool) Config::get('api-toolkit.repositories.allow_random_order', false);
    }
}
