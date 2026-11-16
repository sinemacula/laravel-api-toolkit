<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\FilterOperator;

/**
 * Filter operator handler for the $in token.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class InOperator implements FilterOperator
{
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
        $query->getQuery()->whereIn($column, (array) $value);
    }
}
