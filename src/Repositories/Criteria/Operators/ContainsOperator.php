<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\ExpandsValueList;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;

/**
 * Filter operator handler for the $contains (JSON containment) token.
 *
 * An inexpressible clause throws, as dropping it would widen the result set.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ContainsOperator implements ExpandsValueList, FilterOperator
{
    /**
     * Apply the JSON containment constraint to the query builder.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $column
     * @param  mixed  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    #[\Override]
    public function apply(Builder $query, string $column, mixed $value, FilterContext $context): void
    {
        $items = $this->listItems($value);

        if ($items !== null) {
            $this->applyCommaSeparated($query, $column, $items, $context);
            return;
        }

        $query->getQuery()->whereJsonContains($column, $value, $context->sqlBoolean());
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
        if ($this->isJsonContainable($value) || !is_string($value) || !str_contains($value, ',')) {
            return null;
        }

        return array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '');
    }

    /**
     * Determine whether the value can be passed straight to whereJsonContains.
     *
     * @param  mixed  $value
     * @return bool
     */
    private function isJsonContainable(mixed $value): bool
    {
        if (is_array($value) || is_object($value)) {
            return true;
        }

        return is_string($value) && json_validate($value);
    }

    /**
     * Apply the split items as a grouped JSON containment constraint.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $column
     * @param  array<int, string>  $items
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    private function applyCommaSeparated(Builder $query, string $column, array $items, FilterContext $context): void
    {
        if (empty($items)) {
            return;
        }

        $callback = function (Builder $query) use ($column, $items): void {
            $this->applyJsonContainsGroup($query, $column, $items);
        };

        if ($context->isOr()) {
            $query->orWhere($callback);
        } else {
            $query->where($callback);
        }
    }

    /**
     * Apply each item as an OR-combined JSON containment within the group.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $column
     * @param  array<int, string>  $items
     * @return void
     */
    private function applyJsonContainsGroup(Builder $query, string $column, array $items): void
    {
        foreach ($items as $index => $item) {
            if ($index === 0) {
                $query->getQuery()->whereJsonContains($column, $item);
            } else {
                $query->getQuery()->orWhereJsonContains($column, $item);
            }
        }
    }
}
