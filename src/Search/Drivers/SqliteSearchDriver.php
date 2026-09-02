<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Search driver serving a SQLite connection as a development connection.
 *
 * SQLite carries neither the trigram operator classes nor the n-gram parser the
 * other engines answer a substring from, and it offers no way to prove that a
 * pattern comparison rode an index rather than the table. The driver therefore
 * serves every strategy - so a term behaves the same way locally as it does in
 * front of an engine that indexes it - and claims no proof for any of them.
 *
 * Claiming nothing is what makes the limitation visible: a declaration on this
 * connection is refused unless the connection is listed among the ones where
 * the index proof is waived, which the shipped configuration does for SQLite
 * alone. A connection serving traffic that is listed there has reinstated the
 * full-table scan the declaration exists to prevent.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SqliteSearchDriver implements SearchDriver
{
    /**
     * Return the match strategies this driver implements.
     *
     * @return array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>
     */
    #[\Override]
    public function supportedStrategies(): array
    {
        return SearchStrategy::cases();
    }

    /**
     * Return why the driver cannot resolve the given strategies from an index
     * when they are declared together, or null when it can.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>  $strategies
     * @return string|null
     */
    #[\Override]
    public function combinationDefect(array $strategies): ?string
    {
        return null;
    }

    /**
     * Determine whether the driver can prove the strategy is index backed.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  \Illuminate\Database\Connection  $connection
     * @return bool
     */
    #[\Override]
    public function canVerifyIndexBacking(SearchStrategy $strategy, Connection $connection): bool
    {
        return false;
    }

    /**
     * Return what the columns are missing before the strategy can be served
     * from an index on this connection, keyed by column.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    #[\Override]
    public function indexDefects(SearchStrategy $strategy, array $columns, string $table, Connection $connection): array
    {
        return [];
    }

    /**
     * Apply the search predicate for the given columns to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return void
     */
    #[\Override]
    public function apply(Builder $query, array $columns, SearchTerm $term, SearchStrategy $strategy): void
    {
        foreach ($columns as $column) {

            $qualified = $query->qualifyColumn($column);

            if (!$strategy->matchesByPattern()) {

                $query->orWhere($qualified, '=', $term->pattern($strategy));

                continue;
            }

            // @phpstan-ignore staticMethod.dynamicCall
            $query->orWhereRaw(
                sprintf('%s like ? escape \'%s\'', $query->getQuery()->getGrammar()->wrap($qualified), SearchTerm::ESCAPE_CHARACTER),
                [$term->pattern($strategy)],
            );
        }
    }
}
