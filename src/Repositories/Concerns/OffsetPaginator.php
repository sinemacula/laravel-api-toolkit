<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use SineMacula\ApiToolkit\Config\QuerySettings;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Pagination\CappedLengthAwarePaginator;

/**
 * Reads an offset page, counting the matches only when the page cannot tell.
 *
 * The count admits no more rows than the pages the offset cap allows, so a
 * larger match reports those pages and flags its total as capped.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class OffsetPaginator
{
    /**
     * Refuse a page past the last one the offset cap allows.
     *
     * Clamping it would serve another page than the one asked for.
     *
     * @param  int  $page
     * @param  int  $limit
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function guard(int $page, int $limit): void
    {
        $lastPage = $this->lastPage($limit);

        if ($lastPage !== null && $page > $lastPage) {
            throw QueryTooExpensiveException::exceeded('page', '', QueryCostLimits::MAX_OFFSET, $lastPage, $page);
        }
    }

    /**
     * Read the given page of the query, once guard() has accepted it.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  int  $page
     * @param  int  $limit
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, TModel>
     */
    public function paginate(Builder $query, int $page, int $limit): LengthAwarePaginator
    {
        $fetch = $query->clone();
        $fetch->getQuery()->forPage($page, $limit);

        $items = $fetch->get();
        $count = $items->count();

        if ($count < $limit && ($count > 0 || $page === 1)) {
            return $this->paginator(LengthAwarePaginator::class, $items, ($page - 1) * $limit + $count, $limit, $page);
        }

        $lastPage = $this->lastPage($limit);
        $window   = $lastPage === null ? null : $lastPage * $limit;
        $total    = $this->count($query->toBase(), $query->qualifyColumn('*'), $window);

        if ($window !== null && $total > $window) {
            return $this->paginator(CappedLengthAwarePaginator::class, $items, $window, $limit, $page);
        }

        // The rows just read prove at least this many match
        $total = $count === 0 ? $total : max($total, $page * $limit);

        return $this->paginator(LengthAwarePaginator::class, $items, $total, $limit, $page);
    }

    /**
     * Resolve the last page the offset cap allows, or null when it is disabled.
     *
     * @param  int  $limit
     * @return int|null
     */
    private function lastPage(int $limit): ?int
    {
        $ceiling = QuerySettings::costCap(QueryCostLimits::MAX_OFFSET);

        return $ceiling > 0 ? intdiv($ceiling, $limit) + 1 : null;
    }

    /**
     * Count the rows the query matches, admitting at most one past the window.
     *
     * Only the pagination is removed, so the count sees the rows the pages do.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @param  string  $wildcard
     * @param  int|null  $window
     * @return int
     */
    private function count(QueryBuilder $base, string $wildcard, ?int $window): int
    {
        $slice = empty($base->unions)
            ? $base->cloneWithout(['orders', 'limit', 'offset'])->cloneWithoutBindings(['order'])
            : $base->cloneWithout(['unionOrders', 'unionLimit', 'unionOffset'])->cloneWithoutBindings(['unionOrder']);

        $this->project($slice, $wildcard);

        if ($window !== null) {
            $slice->limit($window + 1);
        }

        $outer = $slice->newQuery()->fromSub($slice, 'aggregate_table');

        // A new query reads from a replica, which a writer-pinned read must not
        if ($slice->useWritePdo) {
            $outer->useWritePdo();
        }

        return $outer->count();
    }

    /**
     * Narrow the projection to a constant where it cannot change the rows.
     *
     * Grouped, distinct and union queries keep theirs, since it shapes the rows
     * they return; a union's must also match its other branches.
     *
     * @param  \Illuminate\Database\Query\Builder  $slice
     * @param  string  $wildcard
     * @return void
     */
    private function project(QueryBuilder $slice, string $wildcard): void
    {
        if (!empty($slice->unions)) {
            return;
        }

        $grouped = !empty($slice->groups) || !empty($slice->havings);

        if (!$grouped && $slice->distinct === false) {
            $slice->select(new Expression('1'));
        } elseif ($grouped && $slice->columns === null && !empty($slice->joins)) {
            $slice->select($wildcard);
        }
    }

    /**
     * Build the paginator through the container, as Eloquent does.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @template TPaginator of \Illuminate\Pagination\LengthAwarePaginator
     *
     * @param  class-string<TPaginator>  $class
     * @param  \Illuminate\Database\Eloquent\Collection<int, TModel>  $items
     * @param  int  $total
     * @param  int  $limit
     * @param  int  $page
     * @return TPaginator
     */
    private function paginator(string $class, Collection $items, int $total, int $limit, int $page): LengthAwarePaginator
    {
        return Container::getInstance()->makeWith($class, [
            'items'       => $items,
            'total'       => $total,
            'perPage'     => $limit,
            'currentPage' => $page,
            'options'     => ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        ]);
    }
}
