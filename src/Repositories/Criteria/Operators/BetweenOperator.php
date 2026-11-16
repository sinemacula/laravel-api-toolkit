<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\FilterOperator;

/**
 * Filter operator handler for the $between token.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class BetweenOperator implements FilterOperator
{
    /**
     * Apply the between constraint to the query builder.
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
        if (!is_array($value) || count($value) !== 2) {
            return;
        }

        $query->getQuery()->whereBetween($column, $value);
    }
}
