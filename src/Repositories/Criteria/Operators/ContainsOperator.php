<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use SineMacula\ApiToolkit\Contracts\DeclaresValueShape;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Enums\FilterValueShape;

/**
 * Filter operator handler for the $contains (JSON containment) token.
 *
 * Each item matches an element of an array document, or the whole of a scalar
 * document, of the same JSON type; on MySQL, also an element of a nested array.
 * A string is one item, matched as sent; a list matches when every item does.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ContainsOperator implements DeclaresValueShape, FilterOperator
{
    /**
     * Return the shape of the value the operator accepts.
     *
     * @return \SineMacula\ApiToolkit\Enums\FilterValueShape
     */
    #[\Override]
    public function valueShape(): FilterValueShape
    {
        return FilterValueShape::ELEMENTS;
    }

    /**
     * Apply the JSON containment constraint to the query builder.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $column
     * @param  mixed  $value
     * @return void
     */
    #[\Override]
    public function apply(Builder $query, string $column, mixed $value): void
    {
        /** @var array<int, bool|float|int|string> $items */
        $items = (array) $value;

        foreach ($items as $item) {
            $this->whereContains($query->getQuery(), $column, $item);
        }
    }

    /**
     * Constrain the document to contain the item.
     *
     * SQLite's own clause compares the bare SQL value of every member, so it
     * cannot tell true from 1 or an object's values from an array's elements. A
     * number is bound as JSON text, as a bound float keeps only 14 digits.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $column
     * @param  bool|float|int|string  $item
     * @return void
     */
    private function whereContains(QueryBuilder $query, string $column, bool|float|int|string $item): void
    {
        if (!$query->getGrammar() instanceof SQLiteGrammar) {
            $query->whereJsonContains($column, $item);
            return;
        }

        $document = $query->getGrammar()->wrap($column);

        $query->whereExists(static function (QueryBuilder $elements) use ($document, $item): void {

            $elements->selectRaw('1')
                ->from(new Expression("json_each({$document})")) // @phpstan-ignore argument.type
                ->where(new Expression("json_type({$document})"), '<>', 'object'); // @phpstan-ignore argument.type

            match (true) {
                is_bool($item)   => $elements->where('json_each.type', $item ? 'true' : 'false'),
                is_string($item) => $elements->where('json_each.type', 'text')->where('json_each.value', $item),
                default          => $elements->whereIn('json_each.type', ['integer', 'real'])->whereRaw('json_each.value = cast(? as numeric)', [json_encode($item)]),
            };
        });
    }
}
