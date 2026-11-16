<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\FilterOperator;

/**
 * Base handler for binary comparison filter operators.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ComparisonOperator implements FilterOperator
{
    /**
     * Apply the comparison constraint to the query builder.
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
        $query->getQuery()->where($column, $this->operator(), $value);
    }

    /**
     * Return the SQL comparison operator symbol.
     *
     * @return string
     */
    abstract protected function operator(): string;
}
