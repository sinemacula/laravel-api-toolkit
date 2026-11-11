<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Base driver for an engine whose own catalogue can be read back.
 *
 * Columns are always table-qualified so predicates survive application joins.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class EngineSearchDriver implements SearchDriver
{
    /**
     * Create a new engine search driver.
     *
     * @param  \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector  $eligibility
     * @return void
     */
    public function __construct(

        /** Reads which of the table's indexes a proof may reason from */
        protected IndexEligibilityInspector $eligibility = new IndexEligibilityInspector,
    ) {}

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
        return true;
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
        return match ($strategy) {
            SearchStrategy::EXACT     => $this->btreeIndexDefects(SearchStrategy::EXACT, $columns, $table, $connection),
            SearchStrategy::PREFIX    => $this->prefixIndexDefects($columns, $table, $connection),
            SearchStrategy::SUBSTRING => $this->substringIndexDefects($columns, $table, $connection),
        };
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
        if ($columns === []) {
            return;
        }

        match ($strategy) {
            SearchStrategy::EXACT     => $this->applyExactMatch($query, $columns, $term),
            SearchStrategy::PREFIX    => $this->applyPrefixMatch($query, $columns, $term),
            SearchStrategy::SUBSTRING => $this->applySubstringMatch($query, $columns, $term),
        };
    }

    /**
     * Apply the prefix match for the declared columns.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return void
     */
    abstract protected function applyPrefixMatch(Builder $query, array $columns, SearchTerm $term): void;

    /**
     * Apply the anywhere-match for the declared columns.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return void
     */
    abstract protected function applySubstringMatch(Builder $query, array $columns, SearchTerm $term): void;

    /**
     * Return, keyed by column, what blocks an index-served prefix match.
     *
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    abstract protected function prefixIndexDefects(array $columns, string $table, Connection $connection): array;

    /**
     * Return, keyed by column, what blocks an index-served anywhere-match.
     *
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    abstract protected function substringIndexDefects(array $columns, string $table, Connection $connection): array;

    /**
     * Apply the equality match for the declared columns.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return void
     */
    protected function applyExactMatch(Builder $query, array $columns, SearchTerm $term): void
    {
        foreach ($columns as $column) {
            $query->orWhere($query->qualifyColumn($column), '=', $term->pattern(SearchStrategy::EXACT));
        }
    }

    /**
     * Return the column table-qualified and wrapped for a raw fragment.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $column
     * @return string
     */
    protected function wrap(Builder $query, string $column): string
    {
        return $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column));
    }

    /**
     * Return, keyed by column, what blocks serving the strategy from a B-tree.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    protected function btreeIndexDefects(SearchStrategy $strategy, array $columns, string $table, Connection $connection): array
    {
        $indexes = $this->indexes($table, $connection);
        $defects = [];

        foreach ($columns as $column) {

            if ($this->hasBtreeIndexLeadingWith($column, $indexes)) {
                continue;
            }

            $defects[$column] = [sprintf(
                'Column "%s" is declared searchable with the "%s" strategy, which needs an index leading with that column on table "%s"',
                $column,
                $strategy->value,
                $table,
            )];
        }

        return $defects;
    }

    /**
     * Return the table's indexes of a known kind that a proof may rest on.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>
     */
    protected function indexes(string $table, Connection $connection): array
    {
        $eligibility = $this->eligibility->inspect($table, $connection);
        $indexes     = [];

        foreach ($connection->getSchemaBuilder()->getIndexes($table) as $entry) {

            $index = IndexDefinition::fromCatalogueEntry($entry);

            if ($index === null || $index->type === null) {
                continue;
            }

            if (!$eligibility->describes($index->name)) {
                continue;
            }

            $indexes[] = $index;
        }

        return $indexes;
    }

    /**
     * Determine whether an ordinary B-tree index leads with the given column.
     *
     * @param  string  $column
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>  $indexes
     * @return bool
     */
    private function hasBtreeIndexLeadingWith(string $column, array $indexes): bool
    {
        foreach ($indexes as $index) {

            if ($index->type === 'btree' && $index->leadsWith($column)) {
                return true;
            }
        }

        return false;
    }
}
