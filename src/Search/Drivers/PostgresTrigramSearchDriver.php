<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Search driver serving a PostgreSQL connection.
 *
 * Both pattern matches are emitted as case-insensitive comparisons served by a
 * trigram index, which answers a wildcard at either end of the term from the
 * index rather than by reading the table. A prefix is matched the same way as
 * an anywhere-match on purpose: an ordinary B-tree serves a pattern only under
 * a pattern operator class, and only case-sensitively, so the same declaration
 * would answer one set of rows here and another on an engine whose collation
 * folds case. One index type per pattern strategy also leaves the operator with
 * one thing to create.
 *
 * Each column carries its own index and each is matched on its own, because
 * this engine combines the bitmaps of several index scans behind a disjunction
 * rather than losing the index to it.
 *
 * An equality match reads the column as it is stored, so it is emitted as a
 * plain comparison and proved against an ordinary index.
 *
 * The trigram operator classes arrive with an extension the application's own
 * migration installs. The driver can only report its absence, which it does
 * rather than let a declaration that cannot be served reach a request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PostgresTrigramSearchDriver extends EngineSearchDriver
{
    /** @var string The extension carrying the operator classes a pattern match is served by */
    public const string EXTENSION = 'pg_trgm';

    /**
     * Apply the prefix match for the declared columns.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
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
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
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
     * Return what the columns are missing before a prefix match can be served
     * from an index, keyed by column.
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
     * Return what the columns are missing before an anywhere-match can be
     * served from an index, keyed by column.
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
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return void
     */
    private function applyPatternMatch(Builder $query, array $columns, SearchTerm $term, SearchStrategy $strategy): void
    {
        foreach ($columns as $column) {

            // @phpstan-ignore staticMethod.dynamicCall
            $query->orWhereRaw(
                sprintf('%s ilike ? escape \'%s\'', $this->wrap($query, $column), SearchTerm::ESCAPE_CHARACTER),
                [$term->pattern($strategy)],
            );
        }
    }

    /**
     * Return what the columns are missing before a pattern match can be served
     * from a trigram index, keyed by column.
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
     * Determine whether the extension carrying the trigram operator classes is
     * installed on the connection.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return bool
     */
    private function hasTrigramExtension(Connection $connection): bool
    {
        return $connection->select('select 1 from pg_extension where extname = ?', [self::EXTENSION]) !== [];
    }

    /**
     * Determine whether one of the given index definitions is built over a
     * trigram operator class on the column.
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
     * Return the statements that would recreate the table's indexes.
     *
     * Only an index the planner may use for an unqualified predicate is read
     * back: one left behind by a failed concurrent build serves no query, and a
     * partial index serves only a query whose own predicate implies its own, so
     * neither proves a search is index backed.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    private function indexDefinitions(string $table, Connection $connection): array
    {
        $rows = $connection->select(
            'select pg_get_indexdef(i.indexrelid) as indexdef from pg_index i '
            . 'join pg_class c on c.oid = i.indrelid '
            . 'join pg_namespace n on n.oid = c.relnamespace '
            . 'where n.nspname = current_schema() and c.relname = ? and i.indisvalid and i.indpred is null',
            [$table],
        );

        $definitions = [];

        foreach ($rows as $row) {

            $definition = ((array) $row)['indexdef'] ?? null;

            if (!is_string($definition)) {
                continue;
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }
}
