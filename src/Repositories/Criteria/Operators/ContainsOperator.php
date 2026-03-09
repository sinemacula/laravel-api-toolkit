<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;

/**
 * Filter operator handler for the $contains (JSON containment) token.
 *
 * @SuppressWarnings("php:S3776")
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ContainsOperator implements FilterOperator
{
    /**
     * Apply the JSON containment constraint to the query builder.
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
        if (is_array($value) || is_object($value) || (is_string($value) && !empty($value) && json_validate($value))) {

            $query->whereJsonContains($column, $value);
            return;
        }

        if (is_string($value) && str_contains($value, ',')) {

            $items = array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '');

            if (!empty($items)) {
                $query->where(function (Builder $query) use ($column, $items): void {
                    foreach ($items as $index => $item) {
                        $query->{$index === 0 ? 'whereJsonContains' : 'orWhereJsonContains'}($column, $item);
                    }
                });
            }

            return;
        }

        try {
            $query->whereJsonContains($column, $value);
        } catch (\Throwable) { // @codeCoverageIgnore
            // Silently discard: whereJsonContains may throw for non-JSON-compatible scalar values (e.g. null)
        } // @codeCoverageIgnore
    }
}
