<?php

namespace SineMacula\ApiToolkit\Contracts;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;

/**
 * Filter operator handler contract.
 *
 * Defines the single-method contract that all filter operator handlers must
 * implement. Each operator receives the query builder, validated column name,
 * raw filter value, and the current filter dispatch context.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface FilterOperator
{
    /**
     * Apply the operator constraint to the query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  mixed  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    public function apply(Builder $query, string $column, mixed $value, FilterContext $context): void;
}
