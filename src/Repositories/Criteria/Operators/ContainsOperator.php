<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use SineMacula\ApiToolkit\Contracts\DeclaresValueShape;
use SineMacula\ApiToolkit\Contracts\ExpandsValueList;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Enums\FilterValueShape;

/**
 * Filter operator handler for the $contains (JSON containment) token.
 *
 * Each item matches an element of an array document, or the whole of a scalar
 * document, of the same JSON type. A list matches when every item does, a
 * comma-separated string when any of its items does.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ContainsOperator implements DeclaresValueShape, ExpandsValueList, FilterOperator
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
        $items = $this->listItems($value);

        if ($items === null) {

            /** @var array<int, bool|float|int|string> $elements */
            $elements = (array) $value;

            foreach ($elements as $item) {
                $this->whereContains($query->getQuery(), $column, $item, 'and');
            }

            return;
        }

        $query->getQuery()->where(function (QueryBuilder $group) use ($column, $items): void {
            foreach ($items as $item) {
                $this->whereContains($group, $column, $item, 'or');
            }
        });
    }

    /**
     * Return the number of containment clauses the given value expands to.
     *
     * @param  mixed  $value
     * @return int
     */
    #[\Override]
    public function countValueItems(mixed $value): int
    {
        $items = $this->listItems($value);

        return $items === null ? 1 : count($items);
    }

    /**
     * Split a comma-separated value into items, or null for a single clause.
     *
     * @param  mixed  $value
     * @return array<int, string>|null
     */
    private function listItems(mixed $value): ?array
    {
        if (!is_string($value) || !str_contains($value, ',')) {
            return null;
        }

        return array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '');
    }

    /**
     * Constrain the document to contain the item, joined by the given boolean.
     *
     * SQLite's own clause compares the bare SQL value of every member, so it
     * cannot tell true from 1 or an object's values from an array's elements.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $column
     * @param  bool|float|int|string  $item
     * @param  string  $boolean
     * @return void
     */
    private function whereContains(QueryBuilder $query, string $column, bool|float|int|string $item, string $boolean): void
    {
        if (!$query->getGrammar() instanceof SQLiteGrammar) {
            $query->whereJsonContains($column, $item, $boolean);
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
                default          => $elements->whereIn('json_each.type', ['integer', 'real'])->whereRaw('json_each.value = cast(? as numeric)', [$item]),
            };
        }, $boolean);
    }
}
