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
 * An anywhere-match is emitted as a boolean-mode full-text match against a
 * column indexed with the n-gram parser, which is the only arrangement MySQL
 * answers a substring with from an index. The term is bound as a quoted phrase:
 * unquoted, the n-grams it decomposes into are OR-ed together and a search for
 * one word returns every row sharing any two of its characters. The default
 * parser is not an alternative - it indexes whole words, so a term inside a
 * longer word matches nothing, which is a wrong answer rather than a slow one.
 *
 * Each column is matched separately rather than through one match over the
 * declared set. MySQL resolves a match against a full-text index whose column
 * list is exactly the matched one, so a single combined match would need a
 * composite index per declared combination, and the same column declared by two
 * resources would need one index for each. A per-column index serves every
 * combination, and is what the index proof asks for.
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

    /** @var string The escape character the term is escaped with, doubled because the engine reads a string literal before the pattern */
    private const string ESCAPE_LITERAL = SearchTerm::ESCAPE_CHARACTER . SearchTerm::ESCAPE_CHARACTER;

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
        return $query->orWhereRaw(
            sprintf('%s like ? escape \'%s\'', $this->wrap($query, $column), self::ESCAPE_LITERAL),
            [$term->pattern(SearchStrategy::PREFIX)],
        );
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
        return $query->orWhereRaw(
            sprintf('match (%s) against (? in boolean mode)', $this->wrap($query, $column)),
            [$term->phrase()],
        );
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
        return $this->btreeIndexDefects(SearchStrategy::PREFIX, $column, $table, $connection);
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
        if ($this->hasNgramIndex($column, $table, $connection)) {
            return [];
        }

        return [sprintf(
            'Column "%s" is declared searchable with the "%s" strategy, which needs a full-text index over that column alone on table "%s", created with the %s parser',
            $column,
            SearchStrategy::SUBSTRING->value,
            $table,
            self::PARSER,
        )];
    }

    /**
     * Determine whether the column carries a full-text index of its own that
     * was created with the n-gram parser.
     *
     * @param  string  $column
     * @param  string  $table
     * @param  \Illuminate\Database\Connection  $connection
     * @return bool
     */
    private function hasNgramIndex(string $column, string $table, Connection $connection): bool
    {
        $definition = $this->tableDefinition($table, $connection);

        foreach ($this->indexes($table, $connection) as $index) {

            if ($index['type'] === 'fulltext' && $index['columns'] === [$column] && $this->usesNgramParser($index['name'], $definition)) {
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
