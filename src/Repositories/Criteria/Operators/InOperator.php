<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\DeclaresValueShape;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Enums\FilterValueShape;

/**
 * Filter operator handler for the $in token.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class InOperator implements DeclaresValueShape, FilterOperator
{
    /**
     * Return the shape of the value the operator accepts.
     *
     * @return \SineMacula\ApiToolkit\Enums\FilterValueShape
     */
    #[\Override]
    public function valueShape(): FilterValueShape
    {
        return FilterValueShape::LIST;
    }

    /**
     * Apply the in constraint to the query builder.
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
        $query->getQuery()->whereIn($column, $value);
    }
}
