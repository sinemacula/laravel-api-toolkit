<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Binds a connection to the search match shapes it can serve from an index.
 *
 * A driver never degrades: it throws rather than emit a predicate that scans.
 * It is asked about a strategy's whole column set, since one index may serve
 * the set.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface SearchDriver
{
    /**
     * Return the match strategies this driver implements.
     *
     * @return array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>
     */
    public function supportedStrategies(): array;

    /**
     * Return why the given strategies cannot be served together from an index,
     * or null when they can.
     *
     * A surface's strategies are OR-ed, and some engines cannot serve a
     * disjunction from an index.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>  $strategies
     * @return string|null
     */
    public function combinationDefect(array $strategies): ?string;

    /**
     * Determine whether the driver can prove, on the given connection, that a
     * column declared with the strategy is served by an index.
     *
     * A true answer is checked against the live schema; a false one is reported
     * to the operator.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  \Illuminate\Database\Connection  $connection
     * @return bool
     */
    public function canVerifyIndexBacking(SearchStrategy $strategy, Connection $connection): bool;

    /**
     * Return what the columns lack for the strategy to be index-served, keyed
     * by column.
     *
     * A set-wide defect is listed under every column. An unreadable connection
     * must throw, since an empty list reads as a proof.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     *
     * @throws \Throwable
     */
    public function indexDefects(SearchStrategy $strategy, array $columns, string $table, Connection $connection): array;

    /**
     * Apply the search predicate for the given columns to the query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return void
     */
    public function apply(Builder $query, array $columns, SearchTerm $term, SearchStrategy $strategy): void;
}
