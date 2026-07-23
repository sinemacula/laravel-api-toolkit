<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;

/**
 * Base handler for nullity filter operators.
 *
 * Applies a null or not-null constraint to the query builder depending on the
 * negation declared by the concrete subclass, honouring the logical operator of
 * the current filter context.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class NullityOperator implements FilterOperator
{
    /**
     * Apply the nullity constraint to the query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  mixed  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    #[\Override]
    public function apply(Builder $query, string $column, mixed $value, FilterContext $context): void
    {
        $boolean = $context->sqlBoolean();

        $query->getQuery()->whereNull($column, $boolean, $this->isNegated());
    }

    /**
     * Return whether the constraint asserts the column is not null.
     *
     * @return bool
     */
    abstract protected function isNegated(): bool;
}
