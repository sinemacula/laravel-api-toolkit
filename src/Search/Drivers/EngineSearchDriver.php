<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Base driver for an engine whose own catalogue can be read back.
 *
 * Owns the parts of a driver that do not vary with the engine: the equality
 * match, which every supported engine serves from an ordinary B-tree, the
 * catalogue read behind the index proof, and the dispatch from a strategy to
 * the predicate and the proof that belong to it. A concrete driver is left with
 * the two shapes that are genuinely engine-specific - the prefix match and the
 * anywhere-match - and the index each of them needs.
 *
 * Every column is qualified with its table before it reaches a predicate, so a
 * clause written here stays unambiguous under a join the application added
 * around it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class EngineSearchDriver implements SearchDriver
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
        return true;
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
        return match ($strategy) {
            SearchStrategy::EXACT     => $this->btreeIndexDefects(SearchStrategy::EXACT, $columns, $table, $connection),
            SearchStrategy::PREFIX    => $this->prefixIndexDefects($columns, $table, $connection),
            SearchStrategy::SUBSTRING => $this->substringIndexDefects($columns, $table, $connection),
        };
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
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return void
     */
    abstract protected function applyPrefixMatch(Builder $query, array $columns, SearchTerm $term): void;

    /**
     * Apply the anywhere-match for the declared columns.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return void
     */
    abstract protected function applySubstringMatch(Builder $query, array $columns, SearchTerm $term): void;

    /**
     * Return what the columns are missing before a prefix match can be served
     * from an index, keyed by column.
     *
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    abstract protected function prefixIndexDefects(array $columns, string $table, Connection $connection): array;

    /**
     * Return what the columns are missing before an anywhere-match can be
     * served from an index, keyed by column.
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
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
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
     * Return the column qualified with its table and wrapped for this
     * connection's grammar, ready to be written into a raw fragment.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @return string
     */
    protected function wrap(Builder $query, string $column): string
    {
        return $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column));
    }

    /**
     * Return what the columns are missing before a strategy an ordinary index
     * serves can be read from one, keyed by column.
     *
     * The catalogue is read once for the whole declaration rather than once per
     * column: every column is proved against the same answer, and a read apiece
     * would multiply the one round trip this layer is allowed to make.
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
     * Return the indexes declared on the table whose kind the connection names.
     *
     * An index the connection reports without a kind is left out: a driver here
     * proves a match against an index of a particular kind, and an unnamed kind
     * proves nothing about the shape the strategy needs.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>
     */
    protected function indexes(string $table, Connection $connection): array
    {
        $indexes = [];

        foreach ($connection->getSchemaBuilder()->getIndexes($table) as $entry) {

            $index = IndexDefinition::fromCatalogueEntry($entry);

            if ($index === null || $index->type === null) {
                continue;
            }

            $indexes[] = $index;
        }

        return $indexes;
    }

    /**
     * Determine whether an ordinary B-tree index leads with the given column.
     *
     * A composite index counts when the column comes first, since that is the
     * prefix of the key an equality or a leading-literal comparison reads. An
     * index of any other kind does not, whatever it covers.
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
