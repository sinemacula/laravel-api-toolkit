<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;

/**
 * Filter operator handler contract.
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
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    public function apply(Builder $query, string $column, mixed $value, FilterContext $context): void;
}
