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
 * declares the strategies it implements, whether those strategies can be served
 * together, whether it can prove on a given connection that a declared strategy
 * is index-backed, what a declaration is missing when it is not, and how to
 * apply the predicate for a set of columns. Nothing here names a grammar or an
 * index type, so a driver sitting in front of an external engine implements the
 * same contract as one that writes a clause against the connection it was
 * resolved for.
 *
 * A driver never degrades. Asked for a strategy it does not implement, for a
 * combination it cannot resolve from an index, or for one it can prove no index
 * serves on this connection, it throws rather than emitting a predicate that
 * scans. A scan that returns the right rows slowly and an index that quietly
 * returns different rows are the two outcomes this contract exists to make
 * impossible.
 *
 * A declared surface is matched one strategy at a time and the results are
 * OR-ed, so a driver is asked about the whole column set behind a strategy
 * rather than about one column: an engine may resolve that set through a single
 * index, and only the set says which index that is.
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
     * Return why the driver cannot resolve the given strategies from an index
     * when they are declared together, or null when it can.
     *
     * The strategies of one surface are OR-ed into a single predicate, and an
     * engine may serve a strategy from an index only where that predicate is
     * not a disjunction. A driver saying so here is what turns an unservable
     * combination into a refusal rather than into a scan.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>  $strategies
     * @return string|null
     */
    public function combinationDefect(array $strategies): ?string;

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
     * Return what the given columns are missing before the strategy can be
     * served from an index on this connection, keyed by the column carrying
     * each defect, or an empty list when nothing is missing.
     *
     * A defect an engine reports against the set rather than against one column
     * is returned under every column in the set, so a caller reporting per
     * column sees it wherever it applies.
     *
     * Only a driver that claims the proof answers this. One that does not
     * returns an empty list, which says nothing was found rather than that
     * nothing is wrong.
     *
     * A driver reads the live connection to answer, and a connection that
     * cannot be read is left to surface as it failed rather than swallowed: an
     * empty list here means no defect was found, so silence from an unreachable
     * catalogue would read as a proof.
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
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return void
     */
    public function apply(Builder $query, array $columns, SearchTerm $term, SearchStrategy $strategy): void;
}
