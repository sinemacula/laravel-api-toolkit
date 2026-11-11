<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\ColumnCondition;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Search driver serving a PostgreSQL connection.
 *
 * Prefixes also use trigrams: a B-tree serves patterns only case-sensitively.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PostgresTrigramSearchDriver extends EngineSearchDriver
{
    /** @var string The extension providing the trigram operator classes */
    public const string EXTENSION = 'pg_trgm';

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
    #[\Override]
    protected function applyPrefixMatch(Builder $query, array $columns, SearchTerm $term): void
    {
        $this->applyPatternMatch($query, $columns, $term, SearchStrategy::PREFIX);
    }

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
    #[\Override]
    protected function applySubstringMatch(Builder $query, array $columns, SearchTerm $term): void
    {
        $this->applyPatternMatch($query, $columns, $term, SearchStrategy::SUBSTRING);
    }

    /**
     * Return, keyed by column, what blocks an index-served prefix match.
     *
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    #[\Override]
    protected function prefixIndexDefects(array $columns, string $table, Connection $connection): array
    {
        return $this->trigramIndexDefects(SearchStrategy::PREFIX, $columns, $table, $connection);
    }

    /**
     * Return, keyed by column, what blocks an index-served anywhere-match.
     *
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    #[\Override]
    protected function substringIndexDefects(array $columns, string $table, Connection $connection): array
    {
        return $this->trigramIndexDefects(SearchStrategy::SUBSTRING, $columns, $table, $connection);
    }

    /**
     * Apply a case-insensitive pattern match for the declared columns.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return void
     */
    private function applyPatternMatch(Builder $query, array $columns, SearchTerm $term, SearchStrategy $strategy): void
    {
        foreach ($columns as $column) {

            $query->orWhere(ColumnCondition::escapedPattern($query->qualifyColumn($column), 'ilike'));
            $query->getQuery()->addBinding($term->pattern($strategy), 'where');
        }
    }

    /**
     * Return, keyed by column, what blocks a trigram-served pattern match.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    private function trigramIndexDefects(SearchStrategy $strategy, array $columns, string $table, Connection $connection): array
    {
        if (!$this->hasTrigramExtension($connection)) {

            $defect = sprintf(
                'The "%s" strategy is served by the %s extension, and that extension is not installed on this connection',
                $strategy->value,
                self::EXTENSION,
            );

            return array_fill_keys($columns, [$defect]);
        }

        $definitions = $this->indexDefinitions($table, $connection);
        $defects     = [];

        foreach ($columns as $column) {

            if ($this->hasTrigramIndex($column, $definitions)) {
                continue;
            }

            $defects[$column] = [sprintf(
                'Column "%s" is declared searchable with the "%s" strategy, which needs a trigram index over that column on table "%s"',
                $column,
                $strategy->value,
                $table,
            )];
        }

        return $defects;
    }

    /**
     * Determine whether the trigram extension is installed on the connection.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return bool
     */
    private function hasTrigramExtension(Connection $connection): bool
    {
        return $connection->selectFromWriteConnection('select 1 from pg_extension where extname = ?', [self::EXTENSION]) !== [];
    }

    /**
     * Determine whether a definition indexes the column with a trigram opclass.
     *
     * A differently collated key breaks the adjacency, so it never matches.
     *
     * @param  string  $column
     * @param  array<int, string>  $definitions
     * @return bool
     */
    private function hasTrigramIndex(string $column, array $definitions): bool
    {
        $pattern = sprintf('/[(,]\s*"?%s"?\s+(?:gin|gist)_trgm_ops\s*[,)]/i', preg_quote($column, '/'));

        foreach ($definitions as $definition) {

            if (preg_match($pattern, $definition) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the catalogue's schema and prefixed relation name for the table.
     *
     * Models name tables without the prefix and with any schema inline.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array{0: string|null, 1: string}
     */
    private function qualify(string $table, Connection $connection): array
    {
        $segments = explode('.', $table);
        $name     = array_pop($segments);

        return [
            $segments === [] ? null : implode('.', $segments),
            $connection->getTablePrefix() . $name,
        ];
    }

    /**
     * Return the definitions of indexes usable by an unqualified predicate.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    private function indexDefinitions(string $table, Connection $connection): array
    {
        [$schema, $name] = $this->qualify($table, $connection);

        $eligibility = $this->eligibility->inspect($table, $connection);

        $rows = $connection->selectFromWriteConnection(
            'select lower(ic.relname) as name, pg_get_indexdef(i.indexrelid) as indexdef from pg_index i '
            . 'join pg_class c on c.oid = i.indrelid '
            . 'join pg_namespace n on n.oid = c.relnamespace '
            . 'join pg_class ic on ic.oid = i.indexrelid '
            . 'where n.nspname = coalesce(?::text, current_schema()) and c.relname = ?',
            [$schema, $name],
        );

        $definitions = [];

        foreach ($rows as $row) {

            $entry      = (array) $row;
            $index      = $entry['name']     ?? null;
            $definition = $entry['indexdef'] ?? null;

            if (!is_string($index) || !is_string($definition)) {
                continue;
            }

            if ($eligibility->disregards($index) || $eligibility->restricts($index)) {
                continue;
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }
}
