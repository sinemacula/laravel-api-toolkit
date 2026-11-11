<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\ColumnCondition;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Search driver serving a SQLite connection as a development connection.
 *
 * Serves every strategy but proves no index, so it needs a waived connection.
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
     * Return why the strategies cannot be index-resolved together, or null.
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
     * Return, keyed by column, what blocks serving the strategy from an index.
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
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
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

            $query->orWhere(ColumnCondition::escapedPattern($qualified));
            $query->getQuery()->addBinding($term->pattern($strategy), 'where');
        }
    }
}
