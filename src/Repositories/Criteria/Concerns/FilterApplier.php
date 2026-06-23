<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;

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

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface */
    private QuerySurface $querySurface;

    /**
     * Apply filters to the query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<string, mixed>|null  $filters
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $operatorRegistry
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface  $querySurface
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function apply(
        Builder $query,
        ?array $filters,
        SchemaIntrospectionProvider $schemaIntrospector,
        OperatorRegistry $operatorRegistry,
        QuerySurface $querySurface
    ): Builder {
        $this->schemaIntrospector = $schemaIntrospector;
        $this->operatorRegistry   = $operatorRegistry;
        $this->querySurface       = $querySurface;

        $this->applyFilters($query, $filters, null, FilterContext::root());

        return $query;
    }

    /**
     * Apply filters recursively to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>|string|null  $filters
     * @param  string|null  $field
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function applyFilters(
        Builder $query,
        array|string|null $filters,
        ?string $field,
        FilterContext $context
    ): Builder {
        if (empty($filters)) {
            return $query;
        }

        if (is_string($filters)) {
            return $this->applySimpleFilter($query, $field, $filters, $context);
        }

        foreach ($filters as $key => $value) {
            $this->applyFilterEntry($query, $key, $value, $field, $context);
        }

        return $query;
    }

    /**
     * Dispatch a single filter entry to the appropriate handler.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $key
     * @param  mixed  $value
     * @param  string|null  $field
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    private function applyFilterEntry(
        Builder $query,
        string $key,
        mixed $value,
        ?string $field,
        FilterContext $context
    ): void {
        if ($this->isConditionOperator($key)) {
            $this->applyConditionOperator($query, $key, $value, $field, $context);
            return;
        }

        if (array_key_exists($key, $this->logicalOperatorMap)) {
            $this->applyLogicalOperator($query, $key, $value, $context);
            return;
        }

        if ($this->schemaIntrospector->isRelation($key, $query->getModel())) {
            if ($this->querySurface->guardRelation($key, $query->getModel())) {
                $this->applyRelationFilter($query, $key, $value, $context);
            }
            return;
        }

        $this->applyFilters($query, $value, $key, $context);
    }

    /**
     * Determine whether the given key is a condition operator.
     *
     * @param  string  $key
     * @return bool
     */
    private function isConditionOperator(string $key): bool
    {
        return $key === '$has' || $key === self::OPERATOR_HASNT || $this->operatorRegistry->has($key);
    }

    /**
     * Apply a simple equality filter.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string|null  $column
     * @param  string  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function applySimpleFilter(Builder $query, ?string $column, string $value, FilterContext $context): Builder
    {
        if ($column && $this->querySurface->guardFilter($column, $query->getModel())) {

            if ($context->getLogicalOperator() === '$or') {
                $query->orWhere($column, $value);
            } else {
                $query->where($column, $value);
            }
        }

        return $query;
    }

    /**
     * Dispatch a condition operator to the appropriate handler.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $operator
     * @param  mixed  $value
     * @param  string|null  $field
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    private function applyConditionOperator(
        Builder $query,
        string $operator,
        mixed $value,
        ?string $field,
        FilterContext $context
    ): void {
        if (in_array($operator, ['$has', self::OPERATOR_HASNT], true)) {

            $this->applyHasFilter($query, $value, $operator, $context);
            return;
        }

        if (!$field || !$this->querySurface->guardFilter($field, $query->getModel())) {
            return;
        }

        $handler = $this->operatorRegistry->resolve($operator);

        if ($handler === null) {
            return;
        }

        $handler->apply($query, $field, $value, $context);
    }

    /**
     * Apply a logical operator group to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
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

        $callback = function (Builder $query) use ($value, $nested): void {
            foreach ($value as $subKey => $subValue) {

                if ($subKey === '$has' || $subKey === self::OPERATOR_HASNT || $this->operatorRegistry->has($subKey)) {
                    $this->applyConditionOperator($query, $subKey, $subValue, null, $nested);
                } elseif (array_key_exists($subKey, $this->logicalOperatorMap)) {
                    $this->applyLogicalOperator($query, $subKey, $subValue, $nested);
                } else {
                    $this->applyFilters($query, $subValue, $subKey, $nested);
                }
            }
        };

        if ($method === 'orWhere') {
            $query->orWhere($callback);
        } else {
            $query->where($callback);
        }
    }

    /**
     * Apply a relation filter using whereHas or orWhereHas.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $relation
     * @param  array<string, mixed>  $filters
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    private function applyRelationFilter(Builder $query, string $relation, array $filters, FilterContext $context): void
    {
        $method          = ($context->getLogicalOperator() === '$or') ? 'orWhereHas' : 'whereHas';
        $relationContext = FilterContext::forRelation($context);

        $this->applyRelationalMethod($query, $method, $relation, function (Builder $query) use ($filters, $relationContext): void {
            $this->processRelationFilters($query, $filters, $relationContext);
        });
    }

    /**
     * Process filters inside a relation scope.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    private function processRelationFilters(Builder $query, array $filters, FilterContext $context): void
    {
        if (isset($filters['$or'])) {

            /** @var array<string, mixed> $orFilters */
            $orFilters = is_array($filters['$or']) ? $filters['$or'] : [];

            $query->where(function (Builder $nested) use ($orFilters, $context): void {
                foreach ($orFilters as $key => $value) {
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
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int|string, mixed>|string  $relations
     * @param  string  $operator
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     */
    private function applyHasFilter(
        Builder $query,
        array|string $relations,
        string $operator,
        FilterContext $context
    ): void {
        $baseMethod = $this->relationalMethodMap[$operator];
        $method     = ($context->getLogicalOperator() === '$or' && $operator === '$has') ? 'orWhereHas' : $baseMethod;

        foreach ((array) $relations as $relation => $filters) {

            if (is_int($relation)) {
                if ($this->querySurface->guardRelation($filters, $query->getModel())) {
                    $this->applyRelationalMethod($query, $method, $filters);
                }
            } elseif ($this->querySurface->guardRelation($relation, $query->getModel())) {
                $this->applyRelationalMethod($query, $method, $relation, function (Builder $query) use ($filters): void {
                    $this->processRelationFilters($query, $filters, FilterContext::root());
                });
            }
        }
    }

    /**
     * Invoke the resolved relational existence method on the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $method
     * @param  string  $relation
     * @param  (\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void)|null  $callback
     * @return void
     */
    private function applyRelationalMethod(
        Builder $query,
        string $method,
        string $relation,
        ?\Closure $callback = null
    ): void {
        match ($method) {
            'orWhereHas'      => $query->orWhereHas($relation, $callback),
            'whereDoesntHave' => $query->whereDoesntHave($relation, $callback),
            default           => $query->whereHas($relation, $callback),
        };
    }
}
