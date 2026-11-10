<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search;

use Illuminate\Contracts\Database\Query\ConditionExpression;
use Illuminate\Database\Grammar;

/**
 * A search condition the query builder has no form of its own for.
 *
 * Neither a pattern match naming its escape character nor a boolean-mode
 * full-text match can be expressed through the builder's where clauses, so the
 * condition is rendered here instead, by the grammar of whichever connection
 * runs the query. The columns are the only part not fixed by the condition
 * itself, and that grammar wraps them as identifiers; the term is never part of
 * the rendering, it is bound against the placeholder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class ColumnCondition implements ConditionExpression
{
    /**
     * Constructor.
     *
     * @param  string  $template
     * @param  array<int, string>  $columns
     */
    private function __construct(

        /** The condition's SQL, with %s standing for the wrapped columns */
        private string $template,

        /** The qualified columns the condition reads */
        private array $columns,
    ) {}

    /**
     * Match the column against a bound pattern escaped with the search term's
     * escape character.
     *
     * @param  string  $column
     * @param  'ilike'|'like'  $operator
     * @return self
     */
    public static function escapedPattern(string $column, string $operator = 'like'): self
    {
        return new self(sprintf('%%s %s ? escape \'%s\'', $operator, SearchTerm::ESCAPE_CHARACTER), [$column]);
    }

    /**
     * Match the columns' full-text index against a bound boolean-mode phrase.
     *
     * @param  array<int, string>  $columns
     * @return self
     */
    public static function booleanFullText(array $columns): self
    {
        return new self('match (%s) against (? in boolean mode)', $columns);
    }

    /**
     * Render the condition for the given grammar.
     *
     * @param  \Illuminate\Database\Grammar  $grammar
     * @return string
     */
    #[\Override]
    public function getValue(Grammar $grammar): string
    {
        return sprintf($this->template, $grammar->columnize($this->columns));
    }
}
