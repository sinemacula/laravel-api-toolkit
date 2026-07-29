<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Operators;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
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
        $boolean = $context->sqlBoolean();

        if ($this->isJsonContainable($value)) {
            $query->getQuery()->whereJsonContains($column, $value, $boolean);
            return;
        }

        if (is_string($value) && str_contains($value, ',')) {
            $this->applyCommaSeparated($query, $column, $value, $context);
            return;
        }

        $this->applyJsonContainsSafely($query, $column, $value, $boolean);
    }

    /**
     * Determine whether the value can be passed directly to a JSON containment
     * constraint.
     *
     * @param  mixed  $value
     * @return bool
     */
    private function isJsonContainable(mixed $value): bool
    {
        if (is_array($value) || is_object($value)) {
            return true;
        }

        return is_string($value) && !empty($value) && json_validate($value);
    }

    /**
     * Split a comma-separated string into trimmed, non-empty items and apply
     * them as a grouped JSON containment constraint.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  string  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    private function applyCommaSeparated(Builder $query, string $column, string $value, FilterContext $context): void
    {
        $items = array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '');

        if (empty($items)) {
            return;
        }

        $callback = function (Builder $query) use ($column, $items): void {
            $this->applyJsonContainsGroup($query, $column, $items);
        };

        if ($context->isOr()) {
            $query->orWhere($callback);
        } else {
            $query->where($callback);
        }
    }

    /**
     * Apply each item as an OR-combined JSON containment constraint within the
     * given query group.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  array<int, string>  $items
     * @return void
     */
    private function applyJsonContainsGroup(Builder $query, string $column, array $items): void
    {
        foreach ($items as $index => $item) {
            if ($index === 0) {
                $query->getQuery()->whereJsonContains($column, $item);
            } else {
                $query->getQuery()->orWhereJsonContains($column, $item);
            }
        }
    }

    /**
     * Apply a JSON containment constraint, logging and discarding values that
     * the active grammar rejects (e.g. non-JSON-compatible scalars like null).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $column
     * @param  mixed  $value
     * @param  string  $boolean
     * @return void
     */
    private function applyJsonContainsSafely(Builder $query, string $column, mixed $value, string $boolean): void
    {
        try {
            $query->getQuery()->whereJsonContains($column, $value, $boolean);
        } catch (\RuntimeException $exception) { // @phpstan-ignore catch.neverThrown
            // Drop the constraint: the grammar may reject a JSON-containment
            // clause for non-JSON scalars (e.g. null). Log it so a recurring
            // rejection is diagnosable rather than silently widening results.
            Log::debug('Dropped unsupported $contains filter constraint', [
                'table'      => $query->getModel()->getTable(),
                'column'     => $column,
                'value_type' => get_debug_type($value),
                'reason'     => $exception->getMessage(),
            ]);
        }
    }
}
