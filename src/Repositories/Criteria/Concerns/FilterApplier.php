<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;

/**
 * Applies filter trees to an Eloquent query builder.
 *
 * @SuppressWarnings("php:S1448")
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FilterApplier
{
    /** @var string */
    private const string OPERATOR_HASNT = '$hasnt';

    /** @var array<string, string> */
    private array $logicalOperatorMap = ['$or' => 'orWhere', '$and' => 'where'];

    /** @var array<string, string> */
    private array $relationalMethodMap = ['$has' => 'whereHas', self::OPERATOR_HASNT => 'whereDoesntHave'];

    /** @var \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider */
    private SchemaIntrospectionProvider $schemaIntrospector;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry */
    private OperatorRegistry $operatorRegistry;

    /**
     * Apply filters to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, mixed>|null  $filters
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $operatorRegistry
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Builder $query, ?array $filters, SchemaIntrospectionProvider $schemaIntrospector, OperatorRegistry $operatorRegistry): Builder
    {
        $this->schemaIntrospector = $schemaIntrospector;
        $this->operatorRegistry   = $operatorRegistry;

        return $this->applyFilters($query, $filters, null, FilterContext::root());
    }

    /**
     * Apply filters recursively to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, mixed>|string|null  $filters
     * @param  string|null  $field
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applyFilters(Builder $query, array|string|null $filters, ?string $field, FilterContext $context): Builder
    {
        if (empty($filters)) {
            return $query;
        }

        if (is_string($filters)) {
            return $this->applySimpleFilter($query, $field, $filters, $context);
        }

        foreach ($filters as $key => $value) {
            if ($key === '$has' || $key === self::OPERATOR_HASNT || $this->operatorRegistry->has($key)) {
                $this->applyConditionOperator($query, $key, $value, $field, $context);
            } elseif (array_key_exists($key, $this->logicalOperatorMap)) {
                $this->applyLogicalOperator($query, $key, $value, $context);
            } elseif ($this->schemaIntrospector->isRelation($key, $query->getModel())) {
                $this->applyRelationFilter($query, $key, $value, $context);
            } else {
                $this->applyFilters($query, $value, $key, $context);
            }
        }

        return $query;
    }

    /**
     * Apply a simple equality filter.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string|null  $column
     * @param  string  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applySimpleFilter(Builder $query, ?string $column, string $value, FilterContext $context): Builder
    {
        if ($column && $this->schemaIntrospector->isSearchable($query->getModel(), $column)) {

            $operator = $context->getLogicalOperator() ?? '$and';

            $query->{$this->logicalOperatorMap[$operator]}($column, $value);
        }

        return $query;
    }

    /**
     * Dispatch a condition operator to the appropriate handler.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $operator
     * @param  mixed  $value
     * @param  string|null  $field
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    private function applyConditionOperator(Builder $query, string $operator, mixed $value, ?string $field, FilterContext $context): void
    {
        if (in_array($operator, ['$has', self::OPERATOR_HASNT], true)) {
            $this->applyHasFilter($query, $value, $operator, $context);
            return;
        }

        if (!$field || !$this->schemaIntrospector->isSearchable($query->getModel(), $field)) {
            return;
        }

        $handler = $this->operatorRegistry->resolve($operator);

        if ($handler !== null) {
            $handler->apply($query, $field, $value, $context);
        }
    }

    /**
     * Apply a logical operator group to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $operator
     * @param  array<string, mixed>  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    private function applyLogicalOperator(Builder $query, string $operator, array $value, FilterContext $context): void
    {
        $method = ($context->getLogicalOperator() === '$and' && $operator === '$or')
            ? 'where' : $this->logicalOperatorMap[$operator];
        $nested = FilterContext::nested($operator, $context);

        $query->{$method}(function (Builder $query) use ($value, $nested): void {
            foreach ($value as $subKey => $subValue) {
                if ($subKey === '$has' || $subKey === self::OPERATOR_HASNT || $this->operatorRegistry->has($subKey)) {
                    $this->applyConditionOperator($query, $subKey, $subValue, null, $nested);
                } elseif (array_key_exists($subKey, $this->logicalOperatorMap)) {
                    $this->applyLogicalOperator($query, $subKey, $subValue, $nested);
                } else {
                    $this->applyFilters($query, $subValue, $subKey, $nested);
                }
            }
        });
    }

    /**
     * Apply a relation filter using whereHas or orWhereHas.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $relation
     * @param  array<string, mixed>  $filters
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    private function applyRelationFilter(Builder $query, string $relation, array $filters, FilterContext $context): void
    {
        $method          = ($context->getLogicalOperator() === '$or') ? 'orWhereHas' : 'whereHas';
        $relationContext = FilterContext::forRelation($context);

        $query->{$method}($relation, function (Builder $query) use ($filters, $relationContext): void {
            $this->processRelationFilters($query, $filters, $relationContext);
        });
    }

    /**
     * Process filters inside a relation scope.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, mixed>  $filters
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    private function processRelationFilters(Builder $query, array $filters, FilterContext $context): void
    {
        if (isset($filters['$or'])) {
            $query->where(function ($nested) use ($filters, $context): void {
                foreach ($filters['$or'] as $key => $value) {
                    $this->applyFilters($nested, $value, $key, FilterContext::nested('$or', $context));
                }
            });
        } else {
            foreach ($filters as $key => $value) {
                $this->applyFilters($query, $value, $key, $context);
            }
        }
    }

    /**
     * Apply a $has or $hasnt relational filter.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<int|string, mixed>|string  $relations
     * @param  string  $operator
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    private function applyHasFilter(Builder $query, array|string $relations, string $operator, FilterContext $context): void
    {
        $baseMethod = $this->relationalMethodMap[$operator];
        $method     = ($context->getLogicalOperator() === '$or' && $operator === '$has') ? 'orWhereHas' : $baseMethod;

        foreach ((array) $relations as $relation => $filters) {

            if (is_int($relation)) {
                if ($this->schemaIntrospector->isRelation($filters, $query->getModel())) {
                    $query->{$method}($filters);
                }
            } elseif ($this->schemaIntrospector->isRelation($relation, $query->getModel())) {
                $query->{$method}($relation, function (Builder $query) use ($filters): void {
                    $this->processRelationFilters($query, $filters, FilterContext::root());
                });
            }
        }
    }
}
