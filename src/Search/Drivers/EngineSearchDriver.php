<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
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
     * Return what the column is missing before the strategy can be served from
     * an index on this connection.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    #[\Override]
    public function indexDefects(SearchStrategy $strategy, string $column, string $table, Connection $connection): array
    {
        return match ($strategy) {
            SearchStrategy::EXACT     => $this->btreeIndexDefects(SearchStrategy::EXACT, $column, $table, $connection),
            SearchStrategy::PREFIX    => $this->prefixIndexDefects($column, $table, $connection),
            SearchStrategy::SUBSTRING => $this->substringIndexDefects($column, $table, $connection),
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
        foreach ($columns as $column) {

            match ($strategy) {
                SearchStrategy::EXACT     => $this->applyExactMatch($query, $column, $term),
                SearchStrategy::PREFIX    => $this->applyPrefixMatch($query, $column, $term),
                SearchStrategy::SUBSTRING => $this->applySubstringMatch($query, $column, $term),
            };
        }
    }

    /**
     * Apply the prefix match for a single column.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    abstract protected function applyPrefixMatch(Builder $query, string $column, SearchTerm $term): Builder;

    /**
     * Apply the anywhere-match for a single column.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    abstract protected function applySubstringMatch(Builder $query, string $column, SearchTerm $term): Builder;

    /**
     * Return what the column is missing before a prefix match can be served
     * from an index.
     *
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    abstract protected function prefixIndexDefects(string $column, string $table, Connection $connection): array;

    /**
     * Return what the column is missing before an anywhere-match can be served
     * from an index.
     *
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    abstract protected function substringIndexDefects(string $column, string $table, Connection $connection): array;

    /**
     * Apply the equality match for a single column.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    protected function applyExactMatch(Builder $query, string $column, SearchTerm $term): Builder
    {
        return $query->orWhere($query->qualifyColumn($column), '=', $term->pattern(SearchStrategy::EXACT));
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
     * Return what the column is missing before a strategy an ordinary index
     * serves can be read from one.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    protected function btreeIndexDefects(SearchStrategy $strategy, string $column, string $table, Connection $connection): array
    {
        if ($this->hasBtreeIndexLeadingWith($column, $table, $connection)) {
            return [];
        }

        return [sprintf(
            'Column "%s" is declared searchable with the "%s" strategy, which needs an index leading with that column on table "%s"',
            $column,
            $strategy->value,
            $table,
        )];
    }

    /**
     * Return the indexes declared on the table, normalised to a name, an
     * ordered column list, and a type.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, array{name: string, columns: array<int, string>, type: string}>
     */
    protected function indexes(string $table, Connection $connection): array
    {
        $indexes = [];

        foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {

            $normalised = is_array($index) ? $this->normalise($index) : null;

            if ($normalised === null) {
                continue;
            }

            $indexes[] = $normalised;
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
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return bool
     */
    private function hasBtreeIndexLeadingWith(string $column, string $table, Connection $connection): bool
    {
        foreach ($this->indexes($table, $connection) as $index) {

            if ($index['type'] === 'btree' && ($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise one catalogue entry, or return null when it carries no name, no
     * kind, or a column that is not a name.
     *
     * @param  array<mixed>  $index
     * @return array{name: string, columns: array<int, string>, type: string}|null
     */
    private function normalise(array $index): ?array
    {
        $name    = $index['name']    ?? null;
        $type    = $index['type']    ?? null;
        $columns = $index['columns'] ?? null;
        $names   = is_array($columns) ? $this->columnNames($columns) : null;

        if (!is_string($name) || !is_string($type) || $names === null) {
            return null;
        }

        return [
            'name'    => $name,
            'columns' => $names,
            'type'    => strtolower($type),
        ];
    }

    /**
     * Return the column names an index covers, or null when the connection
     * reported one as something other than a name.
     *
     * An entry that is not a name leaves the position of every column after it
     * unknown, and the leading one is what a proof reads, so the whole index is
     * passed over rather than resequenced around the gap.
     *
     * @param  array<mixed>  $columns
     * @return array<int, string>|null
     */
    private function columnNames(array $columns): ?array
    {
        $names = [];

        foreach ($columns as $column) {

            if (!is_string($column)) {
                return null;
            }

            $names[] = $column;
        }

        return $names;
    }
}
