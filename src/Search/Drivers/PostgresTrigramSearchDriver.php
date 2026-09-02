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
     * Apply the prefix match for a single column.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    #[\Override]
    protected function applyPrefixMatch(Builder $query, string $column, SearchTerm $term): Builder
    {
        return $this->applyPatternMatch($query, $column, $term, SearchStrategy::PREFIX);
    }

    /**
     * Apply the anywhere-match for a single column.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    #[\Override]
    protected function applySubstringMatch(Builder $query, string $column, SearchTerm $term): Builder
    {
        return $this->applyPatternMatch($query, $column, $term, SearchStrategy::SUBSTRING);
    }

    /**
     * Return what the column is missing before a prefix match can be served
     * from an index.
     *
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    #[\Override]
    protected function prefixIndexDefects(string $column, string $table, Connection $connection): array
    {
        return $this->trigramIndexDefects(SearchStrategy::PREFIX, $column, $table, $connection);
    }

    /**
     * Return what the column is missing before an anywhere-match can be served
     * from an index.
     *
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    #[\Override]
    protected function substringIndexDefects(string $column, string $table, Connection $connection): array
    {
        return $this->trigramIndexDefects(SearchStrategy::SUBSTRING, $column, $table, $connection);
    }

    /**
     * Apply a case-insensitive pattern match for a single column.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function applyPatternMatch(Builder $query, string $column, SearchTerm $term, SearchStrategy $strategy): Builder
    {
        return $query->orWhereRaw(
            sprintf('%s ilike ? escape \'%s\'', $this->wrap($query, $column), SearchTerm::ESCAPE_CHARACTER),
            [$term->pattern($strategy)],
        );
    }

    /**
     * Return what the column is missing before a pattern match can be served
     * from a trigram index.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    private function trigramIndexDefects(SearchStrategy $strategy, string $column, string $table, Connection $connection): array
    {
        if (!$this->hasTrigramExtension($connection)) {
            return [sprintf(
                'Column "%s" is declared searchable with the "%s" strategy, which is served by the %s extension, and that extension is not installed on this connection',
                $column,
                $strategy->value,
                self::EXTENSION,
            )];
        }

        if ($this->hasTrigramIndex($column, $table, $connection)) {
            return [];
        }

        return [sprintf(
            'Column "%s" is declared searchable with the "%s" strategy, which needs a trigram index over that column on table "%s"',
            $column,
            $strategy->value,
            $table,
        )];
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
     * Determine whether the column carries an index built over one of the
     * trigram operator classes.
     *
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return bool
     */
    private function hasTrigramIndex(string $column, string $table, Connection $connection): bool
    {
        $pattern = sprintf('/[(,]\s*"?%s"?\s+(?:gin|gist)_trgm_ops\s*[,)]/i', preg_quote($column, '/'));

        foreach ($this->indexDefinitions($table, $connection) as $definition) {

            if (preg_match($pattern, $definition) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the statements that would recreate the table's indexes.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    private function indexDefinitions(string $table, Connection $connection): array
    {
        $rows = $connection->select(
            'select indexdef from pg_indexes where schemaname = current_schema() and tablename = ?',
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
