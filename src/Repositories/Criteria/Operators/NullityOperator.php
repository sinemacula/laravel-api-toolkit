<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\FilterOperator;

/**
 * Base handler for nullity filter operators.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class NullityOperator implements FilterOperator
{
    /**
     * Apply the nullity constraint to the query builder.
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
        $query->getQuery()->whereNull($column, 'and', $this->isNegated());
    }

    /**
     * Return whether the constraint asserts the column is not null.
     *
     * @return bool
     */
    abstract protected function isNegated(): bool;
}
