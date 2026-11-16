<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * Filter operator handler contract.
 *
 * A handler adds AND-joined predicates for one column, and throws a validation
 * exception for an operand it refuses. One that adds nothing is refused.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface FilterOperator
{
    /**
     * Apply the operator constraint to the query builder.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $column
     * @param  mixed  $value
     * @return void
     */
    public function apply(Builder $query, string $column, mixed $value): void;
}
