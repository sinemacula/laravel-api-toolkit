<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Search driver contract.
 *
 * Binds a connection to the match shapes it can serve from an index. A driver
 * declares the strategies it implements, whether it can prove on a given
 * connection that a declared strategy is index-backed, and how to apply the
 * predicate for a set of columns. Nothing here names a grammar or an index
 * type, so a driver sitting in front of an external engine implements the same
 * contract as one that writes a clause against the connection it was resolved
 * for.
 *
 * A driver never degrades. Asked for a strategy it does not implement, or for
 * one it can prove no index serves on this connection, it throws rather than
 * emitting a predicate that scans. A scan that returns the right rows slowly
 * and an index that quietly returns different rows are the two outcomes this
 * contract exists to make impossible.
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
     * Determine whether the driver can prove, on the given connection, that a
     * column declared with the strategy is served by an index.
     *
     * A driver answering true is held to that proof: the declaration is checked
     * against the live schema and refused when no index backs it. A driver
     * answering false cannot inspect the connection, so the gap is reported to
     * the operator rather than hidden behind a predicate that silently scans.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  \Illuminate\Database\Connection  $connection
     * @return bool
     */
    public function canVerifyIndexBacking(SearchStrategy $strategy, Connection $connection): bool;

    /**
     * Apply the search predicate for the given columns to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return void
     */
    public function apply(Builder $query, array $columns, SearchTerm $term, SearchStrategy $strategy): void;
}
