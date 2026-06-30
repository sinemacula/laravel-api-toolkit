<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;

/**
 * Base handler for binary comparison filter operators.
 *
 * Applies a single comparison constraint to the query builder using the SQL
 * operator symbol declared by the concrete subclass, honouring the logical
 * operator of the current filter context.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ComparisonOperator implements FilterOperator
{
    /**
     * Apply the comparison constraint to the query builder.
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
        $boolean = $context->getLogicalOperator() === '$or' ? 'or' : 'and';

        $query->getQuery()->where($column, $this->operator(), $value, $boolean);
    }

    /**
     * Return the SQL comparison operator symbol.
     *
     * @return string
     */
    abstract protected function operator(): string;
}
