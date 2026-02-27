<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Applies filter conditions trait.
 *
 * Provides methods for applying condition and logical operators to Eloquent
 * query builders, used by the API criteria when processing filter expressions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait AppliesFilterConditions
{
    /**
     * The resolved Eloquent where method name for the current condition
     * context.
     *
     * Set by {@see applyConditionOperator()} before delegating to
     * sub-methods, avoiding the need to pass the logical operator through
     * every layer of the condition chain.
     *
     * @var string
     */
    private string $currentWhereMethod = 'where';

    /**
     * Apply a condition operator to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $operator
     * @param  mixed  $value
     * @param  string|null  $field
     * @param  string|null  $lastLogicalOperator
     * @return void
     */
    private function applyConditionOperator(Builder $query, string $operator, mixed $value, ?string $field, ?string $lastLogicalOperator): void
    {
        $this->currentWhereMethod = $this->logicalOperatorMap[$lastLogicalOperator ?? '$and'];

        if (in_array($operator, ['$has', self::OPERATOR_HAS_NOT], true)) {
            $this->applyHasFilter($query, $value, $operator, $lastLogicalOperator);
        } else {
            $this->applyCondition($query, $operator, $value, $field);
        }
    }

    /**
     * Apply a logical operator to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $operator
     * @param  array<int|string, mixed>  $value
     * @param  string|null  $lastLogicalOperator
     * @return void
     */
    private function applyLogicalOperator(Builder $query, string $operator, array $value, ?string $lastLogicalOperator): void
    {
        $method = $this->determineLogicalMethod($operator, $lastLogicalOperator);

        $query->{$method}(function (Builder $query) use ($value, $operator): void {

            foreach ($value as $subKey => $subValue) {

                if ($this->isConditionOperator($subKey)) {
                    $this->applyConditionOperator($query, $subKey, $subValue, null, $operator);
                } elseif ($this->isLogicalOperator($subKey)) {
                    $this->applyLogicalOperator($query, $subKey, $subValue, $operator);
                } else {
                    $this->applyFilters($query, $subValue, $subKey, $operator);
                }
            }
        });
    }

    /**
     * Determine the method to use for logical operators.
     *
     * @param  string  $operator
     * @param  string|null  $lastLogicalOperator
     * @return string
     */
    private function determineLogicalMethod(string $operator, ?string $lastLogicalOperator): string
    {
        return ($lastLogicalOperator === '$and' && $operator === '$or')
            ? 'where'
            : $this->logicalOperatorMap[$operator];
    }

    /**
     * Determine if the given operator is conditional.
     *
     * @param  string|null  $operator
     * @return bool
     */
    private function isConditionOperator(?string $operator = null): bool
    {
        return array_key_exists($operator, $this->conditionOperatorMap);
    }

    /**
     * Handle a condition based on its operator.
     *
     * Uses {@see $currentWhereMethod} set by the calling
     * {@see applyConditionOperator()} to determine the Eloquent where
     * method.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $operator
     * @param  mixed  $value
     * @param  string|null  $column
     * @return void
     */
    private function applyCondition(Builder $query, string $operator, mixed $value, ?string $column): void
    {
        // @phpstan-ignore method.notFound (getModel() is provided by Eloquent Builder)
        if (!$column || !in_array($column, $this->getSearchableColumns($query->getModel()), true)) {
            return;
        }

        match ($operator) {
            '$in'       => $query->whereIn($column, (array) $value),
            '$between'  => $this->applyBetween($query, $column, $value),
            '$contains' => $this->applyJsonContains($query, $column, $value),
            '$null'     => $this->applyNullCondition($query, $column, true),
            '$notNull'  => $this->applyNullCondition($query, $column, false),
            default     => $this->applyDefaultCondition($query, $column, $operator, $value),
        };
    }

    /**
     * Apply JSON contains condition if the value is valid JSON.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $column
     * @param  mixed  $value
     * @return void
     */
    private function applyJsonContains(Builder $query, string $column, mixed $value): void
    {
        if (is_array($value) || is_object($value) || $this->isValidJson($value)) {
            $query->whereJsonContains($column, $value);

            return;
        }

        if (is_string($value) && str_contains($value, ',')) {
            $this->applyJsonContainsCsv($query, $column, $value);

            return;
        }

        try {
            $query->whereJsonContains($column, $value);
        } catch (\Throwable $exception) { // @codeCoverageIgnore
            // Silently discard: the value may be incompatible with the
            // column's JSON structure; falling through is the safest
            // behaviour since we cannot know the schema at runtime.
        } // @codeCoverageIgnore
    }

    /**
     * Apply JSON contains condition for a comma-separated value string.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $column
     * @param  string  $value
     * @return void
     */
    private function applyJsonContainsCsv(Builder $query, string $column, string $value): void
    {
        $items = array_filter(array_map('trim', explode(',', $value)));

        if (empty($items)) {
            return;
        }

        $query->where(function (Builder $query) use ($column, $items): void {
            foreach ($items as $index => $item) {
                $method = $index === 0 ? 'whereJsonContains' : 'orWhereJsonContains';
                $query->{$method}($column, $item);
            }
        });
    }

    /**
     * Check if a string is valid JSON.
     *
     * @param  string|null  $string
     * @return bool
     */
    private function isValidJson(?string $string): bool
    {
        if (empty($string)) {
            return false;
        }

        return json_validate($string);
    }

    /**
     * Apply a null or not null condition to the query.
     *
     * Uses {@see $currentWhereMethod} to determine the Eloquent where
     * method.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $column
     * @param  bool  $isNull
     * @return void
     */
    private function applyNullCondition(Builder $query, string $column, bool $isNull): void
    {
        if ($isNull) {
            $query->{$this->currentWhereMethod . 'Null'}($column);
        } else {
            $query->{$this->currentWhereMethod . 'NotNull'}($column);
        }
    }

    /**
     * Apply a default condition to the query.
     *
     * Uses {@see $currentWhereMethod} to determine the Eloquent where
     * method.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $column
     * @param  string  $operator
     * @param  mixed  $value
     * @return void
     */
    private function applyDefaultCondition(Builder $query, string $column, string $operator, mixed $value): void
    {
        $sqlOperator    = $this->conditionOperatorMap[$operator];
        $formattedValue = $this->formatValueBasedOnOperator($value, $operator);

        $query->{$this->currentWhereMethod}($column, $sqlOperator, $formattedValue);
    }

    /**
     * Format the value based on the specified operator.
     *
     * @param  mixed  $value
     * @param  string  $operator
     * @return mixed
     */
    private function formatValueBasedOnOperator(mixed $value, string $operator): mixed
    {
        return $operator === '$like' ? "%{$value}%" : $value;
    }

    /**
     * Apply a 'between' condition if the value is appropriate.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $field
     * @param  mixed  $value
     * @return void
     */
    private function applyBetween(Builder $query, string $field, mixed $value): void
    {
        if (is_array($value) && count($value) === 2) {
            $query->whereBetween($field, $value);
        }
    }
}
