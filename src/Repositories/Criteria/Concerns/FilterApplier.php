<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\ExpandsValueList;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;

/**
 * Applies filter trees to an Eloquent query builder.
 *
 * The walk carries a cost budget on the dispatch context, so a document that
 * nests too deeply, visits too many keys, or hands an operator too long a value
 * list is refused where it is reached rather than after the whole tree has been
 * built.
 *
 * Every predicate the walk emits is put to the query surface as a (column,
 * operator) pair rather than as a column alone, so the column's declared
 * capability decides whether the operator reaches its handler. The bare
 * shorthand carries no token of its own and is put as the equality it compiles
 * to, so a column is held to the same declaration whichever spelling asks for
 * it.
 *
 * @SuppressWarnings("php:S1448")
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FilterApplier
{
    /** @var array<int, string> The structural (logical + relational) filter operator tokens - the canonical grammar the OpenAPI exporter documents. Keep aligned with the dispatch maps below (guarded by a test). */
    public const array STRUCTURAL_OPERATORS = ['$and', '$or', '$has', self::OPERATOR_HASNT];

    /** @var string */
    private const string OPERATOR_HASNT = '$hasnt';

    /** @var string The token the bare shorthand compiles to, and so the one its column is gated against */
    private const string OPERATOR_EQUAL = '$eq';

    /** @var array<string, string> */
    private array $logicalOperatorMap = ['$or' => 'orWhere', '$and' => 'where'];

    /** @var array<string, string> */
    private array $relationalMethodMap = ['$has' => 'whereHas', self::OPERATOR_HASNT => 'whereDoesntHave'];

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry */
    private OperatorRegistry $operatorRegistry;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface */
    private QuerySurface $querySurface;

    /** @var \SineMacula\ApiToolkit\Query\QueryCostLimits */
    private QueryCostLimits $limits;

    /**
     * Apply filters to the query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<string, mixed>|null  $filters
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $operatorRegistry
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface  $querySurface
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function apply(Builder $query, ?array $filters, OperatorRegistry $operatorRegistry, QuerySurface $querySurface): Builder
    {
        $this->operatorRegistry = $operatorRegistry;
        $this->querySurface     = $querySurface;
        $this->limits           = QueryCostLimits::fromConfig();

        $this->applyFilters($query, $filters, null, FilterContext::root(new FilterCostBudget($this->limits)));

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
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
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
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyFilterEntry(Builder $query, string $key, mixed $value, ?string $field, FilterContext $context): void
    {
        $context->admit($key);

        if ($this->isConditionOperator($key)) {
            $this->applyConditionOperator($query, $key, $value, $field, $context);
            return;
        }

        if (array_key_exists($key, $this->logicalOperatorMap)) {
            $this->applyLogicalOperator($query, $key, $value, $context);
            return;
        }

        $this->routePlainKey($query, $key, $value, $context);
    }

    /**
     * Route a plain (non-operator) key to its relation or column handler.
     *
     * The declared surface routes the key without schema introspection: a
     * declared traversable relation goes to the relation filter, and any other
     * key is gated as a filter column so an undeclared key is rejected at that
     * key before the cached relation lookup is ever reached, rather than
     * descending into its value.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $key
     * @param  mixed  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function routePlainKey(Builder $query, string $key, mixed $value, FilterContext $context): void
    {
        $model = $query->getModel();

        if ($this->querySurface->isDeclaredRelation($key, $model)) {
            $this->applyRelationFilter($query, $key, $value, $context);
            return;
        }

        $this->querySurface->guardFilter($key, $model);

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
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function applySimpleFilter(Builder $query, ?string $column, string $value, FilterContext $context): Builder
    {
        if ($column) {

            $this->querySurface->guardFilterOperator($column, self::OPERATOR_EQUAL, $query->getModel());

            if ($context->isOr()) {
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
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyConditionOperator(Builder $query, string $operator, mixed $value, ?string $field, FilterContext $context): void
    {
        if (in_array($operator, ['$has', self::OPERATOR_HASNT], true)) {

            $this->applyHasFilter($query, $value, $operator, $context);
            return;
        }

        if (!$field) {
            return;
        }

        $this->querySurface->guardFilterOperator($field, $operator, $query->getModel());

        $handler = $this->operatorRegistry->resolve($operator);

        $this->limits->enforce(QueryCostLimits::MAX_IN_ITEMS, $this->countValueItems($handler, $value), 'filters', $context->pointerTo($field) . '/' . $operator);

        if ($handler === null) {
            return;
        }

        $handler->apply($query, $field, $value, $context);
    }

    /**
     * Count the items the given handler will read out of the operator value.
     *
     * A handler that fans a single value out into one predicate per item
     * reports its own count, so a list spelled as a delimited string is capped
     * on the same footing as one spelled as an array.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\FilterOperator|null  $handler
     * @param  mixed  $value
     * @return int
     */
    private function countValueItems(?FilterOperator $handler, mixed $value): int
    {
        if (is_array($value)) {
            return count($value);
        }

        return $handler instanceof ExpandsValueList ? $handler->countValueItems($value) : 1;
    }

    /**
     * Apply a logical operator group to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $operator
     * @param  array<string, mixed>  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyLogicalOperator(Builder $query, string $operator, array $value, FilterContext $context): void
    {
        $method = ($context->getLogicalOperator() === '$and' && $operator === '$or')
            ? 'where' : $this->logicalOperatorMap[$operator];
        $nested = $context->descend($operator, $operator);

        $callback = function (Builder $query) use ($value, $nested): void {
            foreach ($value as $subKey => $subValue) {

                $nested->admit($subKey);

                if ($this->isConditionOperator($subKey)) {
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
     * The relation scope is entered with no logical operator in effect: an OR
     * above the relation is carried by orWhereHas at the parent level, and
     * repeating it inside the subquery would OR the conditions against the
     * correlation predicate, matching every parent row.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $relation
     * @param  array<string, mixed>  $filters
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyRelationFilter(Builder $query, string $relation, array $filters, FilterContext $context): void
    {
        $method = $context->isOr() ? 'orWhereHas' : 'whereHas';
        $nested = $context->descend($relation, null);

        $this->applyRelationalMethod($query, $method, $relation, function (Builder $query) use ($filters, $nested): void {
            $this->processRelationFilters($query, $filters, $nested);
        });
    }

    /**
     * Process filters inside a relation scope.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filters
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function processRelationFilters(Builder $query, array $filters, FilterContext $context): void
    {
        if (isset($filters['$or'])) {

            /** @var array<string, mixed> $orFilters */
            $orFilters = is_array($filters['$or']) ? $filters['$or'] : [];

            $nested = $context->descend('$or', '$or');

            $query->where(function (Builder $group) use ($orFilters, $nested): void {
                foreach ($orFilters as $key => $value) {
                    $nested->admit($key);
                    $this->applyFilters($group, $value, $key, $nested);
                }
            });
        } else {
            foreach ($filters as $key => $value) {
                $context->admit($key);
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
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyHasFilter(Builder $query, array|string $relations, string $operator, FilterContext $context): void
    {
        $baseMethod = $this->relationalMethodMap[$operator];
        $method     = ($context->isOr() && $operator === '$has') ? 'orWhereHas' : $baseMethod;
        $scope      = $context->at($operator);

        foreach ((array) $relations as $relation => $filters) {

            $scope->admit((string) $relation);

            if (is_int($relation)) {

                $this->querySurface->guardRelation($filters, $query->getModel());

                $this->applyRelationalMethod($query, $method, $filters);

            } else {

                $this->querySurface->guardRelation($relation, $query->getModel());

                $nested = $scope->descend($relation, null);

                $this->applyRelationalMethod($query, $method, $relation, function (Builder $query) use ($filters, $nested): void {
                    $this->processRelationFilters($query, $filters, $nested);
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
    private function applyRelationalMethod(Builder $query, string $method, string $relation, ?\Closure $callback = null): void
    {
        match ($method) {
            'orWhereHas'      => $query->orWhereHas($relation, $callback),
            'whereDoesntHave' => $query->whereDoesntHave($relation, $callback),
            default           => $query->whereHas($relation, $callback),
        };
    }
}
