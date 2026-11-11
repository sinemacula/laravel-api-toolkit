<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\ColumnCondition;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Search driver serving a MySQL connection.
 *
 * An anywhere-match is one quoted full-text match over an n-gram index.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MySqlNgramSearchDriver extends EngineSearchDriver
{
    /** @var string The full-text parser an anywhere-match index must use */
    public const string PARSER = 'ngram';

    /**
     * Return why the strategies cannot be index-resolved together, or null.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>  $strategies
     * @return string|null
     */
    #[\Override]
    public function combinationDefect(array $strategies): ?string
    {
        if (!in_array(SearchStrategy::SUBSTRING, $strategies, true) || count($strategies) < 2) {
            return null;
        }

        return sprintf(
            'the "%s" strategy is declared alongside another strategy, and a full-text match OR-ed with any other predicate '
            . 'loses the full-text access path and reads the whole table',
            SearchStrategy::SUBSTRING->value,
        );
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
    #[\Override]
    protected function applyPrefixMatch(Builder $query, array $columns, SearchTerm $term): void
    {
        foreach ($columns as $column) {

            $query->orWhere(ColumnCondition::escapedPattern($query->qualifyColumn($column)));
            $query->getQuery()->addBinding($term->pattern(SearchStrategy::PREFIX), 'where');
        }
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
        $query->orWhere(ColumnCondition::booleanFullText(array_map($query->qualifyColumn(...), $columns)));
        $query->getQuery()->addBinding($term->phrase(), 'where');
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
        return $this->btreeIndexDefects(SearchStrategy::PREFIX, $columns, $table, $connection);
    }

    /**
     * Return, keyed by column, what blocks an index-served anywhere-match.
     *
     * The set is matched as a unit, so each defect applies to every column.
     *
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, array<int, string>>
     */
    #[\Override]
    protected function substringIndexDefects(array $columns, string $table, Connection $connection): array
    {
        $defects = [];
        $minimum = SearchTerm::minimumWordLength();
        $size    = $this->tokenSize($connection);

        if ($size === null) {
            $defects[] = 'The connection did not report the number of characters its n-gram parser tokenises at a time, '
                . 'so a term short enough to produce no tokens cannot be ruled out';
        } elseif ($size > $minimum) {
            $defects[] = sprintf(
                'The connection parses n-grams %d characters at a time, which is longer than the shortest word a search term '
                . 'may carry (%d), so an accepted term would produce no tokens and match nothing',
                $size,
                $minimum,
            );
        }

        if (!$this->hasNgramIndex($columns, $table, $connection)) {
            $defects[] = sprintf(
                'The columns declared with the "%s" strategy ("%s") are matched together, so table "%s" needs one full-text '
                . 'index over exactly that column list, created with the %s parser',
                SearchStrategy::SUBSTRING->value,
                implode('", "', $columns),
                $table,
                self::PARSER,
            );
        }

        return $defects === [] ? [] : array_fill_keys($columns, $defects);
    }

    /**
     * Determine whether one n-gram full-text index covers exactly the columns.
     *
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return bool
     */
    private function hasNgramIndex(array $columns, string $table, Connection $connection): bool
    {
        $declared = $columns;

        sort($declared);

        $definition = $this->tableDefinition($table, $connection);

        foreach ($this->indexes($table, $connection) as $index) {

            $covered = $index->columns;

            sort($covered);

            if ($index->type === 'fulltext' && $covered === $declared && $this->usesNgramParser($index->name, $definition)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the named full-text index declares the n-gram parser.
     *
     * The information schema omits the parser, so the table definition is read.
     *
     * @param  string  $index
     * @param  string  $definition
     * @return bool
     */
    private function usesNgramParser(string $index, string $definition): bool
    {
        $pattern = sprintf(
            '/fulltext key `%s`[^\n]*with parser `%s`/i',
            preg_quote($index, '/'),
            preg_quote(self::PARSER, '/'),
        );

        return preg_match($pattern, $definition) === 1;
    }

    /**
     * Return the n-gram parser's token size, or null when it is not reported.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return int|null
     */
    private function tokenSize(Connection $connection): ?int
    {
        $row  = (array) $connection->selectOne('select @@ngram_token_size as size', [], false);
        $size = $row['size'] ?? null;

        return is_numeric($size) ? (int) $size : null;
    }

    /**
     * Return the statement that would recreate the table.
     *
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return string
     */
    private function tableDefinition(string $table, Connection $connection): string
    {
        $statement  = 'show create table ' . $connection->getQueryGrammar()->wrapTable($table);
        $definition = (array) $connection->selectOne($statement, [], false);

        return is_string($definition['Create Table'] ?? null) ? $definition['Create Table'] : '';
    }
}
