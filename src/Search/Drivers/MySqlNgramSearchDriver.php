<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Search driver serving a MySQL connection.
 *
 * An anywhere-match is emitted as a boolean-mode full-text match against
 * columns indexed with the n-gram parser, which is the only arrangement MySQL
 * answers a substring with from an index. The term is bound as a quoted phrase:
 * unquoted, the n-grams it decomposes into are OR-ed together and a search for
 * one word returns every row sharing any two of its characters. The default
 * parser is not an alternative - it indexes whole words, so a term inside a
 * longer word matches nothing, which is a wrong answer rather than a slow one.
 *
 * Every declared column is matched through one match over the whole set rather
 * than through a match each. A full-text index is an access path, not a filter
 * the optimiser can combine: two matches OR-ed together read the table row by
 * row, and so does a match OR-ed with any other predicate. One match over the
 * declared set keeps the access path, and MySQL resolves it against the
 * full-text index whose column list is that same set, which is the index the
 * proof asks for. Column order is not part of that match, so the proof compares
 * the sets rather than the sequences.
 *
 * The same rule is why a substring declaration may not sit beside another
 * strategy on this connection: the two would be OR-ed and the access path would
 * be lost, which the driver refuses rather than serves as a scan.
 *
 * An equality or a prefix match reads an ordinary B-tree, so both are emitted
 * as plain comparisons and both are proved against an ordinary index.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MySqlNgramSearchDriver extends EngineSearchDriver
{
    /** @var string The parser a full-text index has to be created with before it can answer a substring */
    public const string PARSER = 'ngram';

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
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @return void
     */
    #[\Override]
    protected function applyPrefixMatch(Builder $query, array $columns, SearchTerm $term): void
    {
        foreach ($columns as $column) {

            // @phpstan-ignore staticMethod.dynamicCall
            $query->orWhereRaw(
                sprintf('%s like ? escape \'%s\'', $this->wrap($query, $column), SearchTerm::ESCAPE_CHARACTER),
                [$term->pattern(SearchStrategy::PREFIX)],
            );
        }
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
        $matched = array_map(fn (string $column): string => $this->wrap($query, $column), $columns);

        // @phpstan-ignore staticMethod.dynamicCall
        $query->orWhereRaw(
            sprintf('match (%s) against (? in boolean mode)', implode(', ', $matched)),
            [$term->phrase()],
        );
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
        return $this->btreeIndexDefects(SearchStrategy::PREFIX, $columns, $table, $connection);
    }

    /**
     * Return what the columns are missing before an anywhere-match can be
     * served from an index, keyed by column.
     *
     * The declared set is matched as a unit, so a defect found here belongs to
     * every column in it.
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
     * Determine whether the declared columns carry a full-text index over
     * exactly that set, created with the n-gram parser.
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
     * The parser is absent from the information schema, so it is read back from
     * the table definition, where it is emitted alongside the index it belongs
     * to.
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
     * Return the number of characters the n-gram parser tokenises at a time, or
     * null when the connection did not report it.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return int|null
     */
    private function tokenSize(Connection $connection): ?int
    {
        $row  = (array) $connection->selectOne('select @@ngram_token_size as size');
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
        $definition = (array) $connection->selectOne($statement);

        return is_string($definition['Create Table'] ?? null) ? $definition['Create Table'] : '';
    }
}
