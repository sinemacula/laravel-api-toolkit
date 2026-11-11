<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Contracts\ExpandsValueList;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
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
    /** @var array<int, string> Structural operator tokens, kept aligned with the dispatch maps */
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
     * @param  array<array-key, mixed>|null  $filters
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

        $this->applyEntries($query, $filters ?? [], null, FilterContext::root(new FilterCostBudget($this->limits)));

        return $query;
    }

    /**
     * Apply the value given for a field to the query.
     *
     * Any scalar, falsy ones included, is the bare equality shorthand.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  mixed  $value
     * @param  string  $field
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyFilters(Builder $query, mixed $value, string $field, FilterContext $context): Builder
    {
        if (!is_array($value)) {
            return $this->applySimpleFilter($query, $field, $value, $context);
        }

        return $this->applyEntries($query, $value, $field, $context);
    }

    /**
     * Apply each entry of a filter document to the query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<array-key, mixed>  $filters
     * @param  string|null  $field
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyEntries(Builder $query, array $filters, ?string $field, FilterContext $context): Builder
    {
        foreach ($filters as $key => $value) {
            $this->applyFilterEntry($query, $this->assertNamedKey($key, $field ?? 'filters'), $value, $field, $context);
        }

        return $query;
    }

    /**
     * Assert that a filter position carries a named key, not a list index.
     *
     * @param  mixed  $key
     * @param  string  $position
     * @return string
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function assertNamedKey(mixed $key, string $position): string
    {
        if (is_string($key)) {
            return $key;
        }

        $message = 'The %s filter must name a field or a condition. To match '
            . 'any of several values, give the field a condition such as $in.';

        throw ValidationException::withMessages([$position => sprintf($message, $position)]);
    }

    /**
     * Assert that a filter position carries a document of conditions.
     *
     * @param  mixed  $value
     * @param  string  $position
     * @return array<array-key, mixed>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function assertDocument(mixed $value, string $position): array
    {
        if (is_array($value)) {
            return $value;
        }

        throw ValidationException::withMessages([$position => sprintf('The %s filter must be a document of conditions.', $position)]);
    }

    /**
     * Assert that a relation existence filter lists a relation name.
     *
     * @param  mixed  $relation
     * @param  string  $operator
     * @return string
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function assertRelationName(mixed $relation, string $operator): string
    {
        if (is_string($relation)) {
            return $relation;
        }

        throw ValidationException::withMessages([$operator => sprintf('The %s filter must list relation names.', $operator)]);
    }

    /**
     * Dispatch a single filter entry to the appropriate handler.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
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
            $this->applyLogicalOperator($query, $key, $this->assertDocument($value, $key), $context);
            return;
        }

        $this->routePlainKey($query, $key, $value, $context);
    }

    /**
     * Route a plain (non-operator) key to its relation or column handler.
     *
     * Undeclared keys are rejected before any relation lookup or descent.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
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
            $this->applyRelationFilter($query, $key, $this->assertDocument($value, $key), $context);
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
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $column
     * @param  mixed  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function applySimpleFilter(Builder $query, string $column, mixed $value, FilterContext $context): Builder
    {
        $this->querySurface->guardFilterOperator($column, self::OPERATOR_EQUAL, $query->getModel());

        $stored = PureEnumFilterValue::resolve($query->getModel(), $column, $value);

        if ($context->isOr()) {
            $query->orWhere($column, '=', $stored);
        } else {
            $query->where($column, '=', $stored);
        }

        return $query;
    }

    /**
     * Dispatch a condition operator to the appropriate handler.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
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

            $this->applyHasFilter($query, is_string($value) ? $value : $this->assertDocument($value, $operator), $operator, $context);
            return;
        }

        if ($field === null) {
            return;
        }

        $this->querySurface->guardFilterOperator($field, $operator, $query->getModel());

        $handler = $this->operatorRegistry->resolve($operator);

        $this->limits->enforce(QueryCostLimits::MAX_IN_ITEMS, $this->countValueItems($handler, $value), 'filters', $context->pointerTo($field) . '/' . $operator);

        if ($handler === null) {
            return;
        }

        $handler->apply($query, $field, PureEnumFilterValue::resolve($query->getModel(), $field, $value), $context);
    }

    /**
     * Count the items the given handler will read out of the operator value.
     *
     * A handler that fans a delimited string out reports its own count.
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
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $operator
     * @param  array<array-key, mixed>  $value
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

        $callback = function (Builder $query) use ($value, $nested, $operator): void {
            foreach ($value as $subKey => $subValue) {
                $this->applyLogicalEntry($query, $this->assertNamedKey($subKey, $operator), $subValue, $nested);
            }
        };

        if ($method === 'orWhere') {
            $query->orWhere($callback);
        } else {
            $query->where($callback);
        }
    }

    /**
     * Apply one entry of a logical operator group to the query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $key
     * @param  mixed  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyLogicalEntry(Builder $query, string $key, mixed $value, FilterContext $context): void
    {
        $context->admit($key);

        if ($this->isConditionOperator($key)) {
            $this->applyConditionOperator($query, $key, $value, null, $context);
        } elseif (array_key_exists($key, $this->logicalOperatorMap)) {
            $this->applyLogicalOperator($query, $key, $this->assertDocument($value, $key), $context);
        } else {
            $this->applyFilters($query, $value, $key, $context);
        }
    }

    /**
     * Apply a relation filter using whereHas or orWhereHas.
     *
     * An OR inside the subquery would disjoin the correlation predicate.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $relation
     * @param  array<array-key, mixed>  $filters
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
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<array-key, mixed>  $filters
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     *
     * @phpstan-ignore throws.unusedType, throws.unusedType
     */
    private function processRelationFilters(Builder $query, array $filters, FilterContext $context): void
    {
        // Grouped so the clause beside the correlation predicate stays an `and`
        $query->where(function (Builder $group) use ($filters, $context): void {
            foreach ($filters as $key => $value) {

                $key = $this->assertNamedKey($key, 'relation');

                if ($key === '$or') {
                    $this->applyRelationOrGroup($group, $value, $context);
                    continue;
                }

                $context->admit($key);

                $this->applyFilters($group, $value, $key, $context);
            }
        });
    }

    /**
     * Apply an $or carried by a relation filter as its own grouped disjunction.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  mixed  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     *
     * @phpstan-ignore throws.unusedType, throws.unusedType
     */
    private function applyRelationOrGroup(Builder $query, mixed $value, FilterContext $context): void
    {
        /** @var array<string, mixed> $filters */
        $filters = is_array($value) ? $value : [];

        $nested = $context->descend('$or', '$or');

        $query->where(function (Builder $group) use ($filters, $nested): void {
            foreach ($filters as $key => $subValue) {
                $nested->admit($key);
                $this->applyFilters($group, $subValue, $key, $nested);
            }
        });
    }

    /**
     * Apply a $has or $hasnt relational filter.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<array-key, mixed>|string  $relations
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

                $relation = $this->assertRelationName($filters, $operator);

                $this->querySurface->guardRelation($relation, $query->getModel());

                $this->applyRelationalMethod($query, $method, $relation);

            } else {

                $this->querySurface->guardRelation($relation, $query->getModel());

                $nested  = $scope->descend($relation, null);
                $filters = $this->assertDocument($filters, $relation);

                $this->applyRelationalMethod($query, $method, $relation, function (Builder $query) use ($filters, $nested): void {
                    $this->processRelationFilters($query, $filters, $nested);
                });
            }
        }
    }

    /**
     * Invoke the resolved relational existence method on the query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
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
