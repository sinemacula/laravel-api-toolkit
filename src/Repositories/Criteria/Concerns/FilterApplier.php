<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use SineMacula\ApiToolkit\Contracts\ExpandsValueList;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Validation\Rejection;

/**
 * Applies filter documents to an Eloquent query builder.
 *
 * The entries of a document AND together, and every key at every depth is
 * routed the same way. An entry that adds no condition is refused, as dropping
 * it would widen the result set.
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
    private array $logicalOperatorMap = ['$and' => 'and', '$or' => 'or'];

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

        $this->applyDocument($query, $filters ?? [], FilterContext::root(new FilterCostBudget($this->limits)));

        return $query;
    }

    /**
     * Apply each entry of a filter document to the query, ANDed together.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<array-key, mixed>  $document
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyDocument(Builder $query, array $document, FilterContext $context): void
    {
        foreach ($document as $key => $value) {

            $key = $this->assertNamedKey($key, $context->path());

            $context->admit($key);

            $applied = count($query->getQuery()->wheres);

            $this->applyEntry($query, $key, $value, $context);

            $this->assertNarrowed($query, $applied, $context->at($key)->path());
        }
    }

    /**
     * Dispatch a single document entry to the handler its key names.
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
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyEntry(Builder $query, string $key, mixed $value, FilterContext $context): void
    {
        if (array_key_exists($key, $this->logicalOperatorMap)) {
            $this->applyGroup($query, $key, $value, $context);
            return;
        }

        if (array_key_exists($key, $this->relationalMethodMap)) {
            $this->applyHasFilter($query, $key, $value, $context);
            return;
        }

        if ($this->operatorRegistry->has($key)) {

            $position = $context->at($key)->path();

            throw Rejection::at($position, 'filters.fieldless_condition', ['position' => $position]);
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
            $this->applyRelation($query, 'whereHas', $key, $this->assertDocument($value, $context->at($key)->path()), $context);
            return;
        }

        $this->querySurface->guardFilter($key, $model);

        $this->applyColumn($query, $key, $value, $context->at($key));
    }

    /**
     * Apply the value given for a column to the query.
     *
     * Any scalar, falsy ones included, is the bare equality shorthand.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $column
     * @param  mixed  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyColumn(Builder $query, string $column, mixed $value, FilterContext $context): void
    {
        if (!is_array($value)) {
            $this->applyEquality($query, $column, $value);
            return;
        }

        if ($value === []) {
            throw Rejection::at($context->path(), 'filters.empty_document', ['position' => $context->path()]);
        }

        if (array_is_list($value)) {
            throw Rejection::at($context->path(), 'filters.unnamed_key', ['position' => $context->path()]);
        }

        foreach ($value as $token => $operand) {

            $token = (string) $token;

            $context->admit($token);

            $applied = count($query->getQuery()->wheres);

            $this->applyCondition($query, $column, $token, $operand, $context);

            $this->isolateCondition($query->getQuery(), $applied);

            $this->assertNarrowed($query, $applied, $context->at($token)->path());
        }
    }

    /**
     * Apply the bare equality shorthand for a column.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $column
     * @param  mixed  $value
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function applyEquality(Builder $query, string $column, mixed $value): void
    {
        $this->querySurface->guardFilterOperator($column, self::OPERATOR_EQUAL, $query->getModel());

        $query->where($column, '=', PureEnumFilterValue::resolve($query->getModel(), $column, $value));
    }

    /**
     * Apply one condition token to a column, located by the column's context.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $column
     * @param  string  $token
     * @param  mixed  $operand
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyCondition(Builder $query, string $column, string $token, mixed $operand, FilterContext $context): void
    {
        $handler = in_array($token, self::STRUCTURAL_OPERATORS, true) ? null : $this->operatorRegistry->resolve($token);

        if ($handler === null) {

            $position = $context->at($token)->path();

            throw Rejection::at($position, 'filters.not_condition', ['position' => $position]);
        }

        $this->querySurface->guardFilterOperator($column, $token, $query->getModel());

        $this->limits->enforce(QueryCostLimits::MAX_IN_ITEMS, $this->countValueItems($handler, $operand), 'filters', $context->pointerTo($token));

        $handler->apply($query, $column, PureEnumFilterValue::resolve($query->getModel(), $column, $operand));
    }

    /**
     * Count the items the given handler will read out of the operator value.
     *
     * A handler that fans a delimited string out reports its own count.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\FilterOperator  $handler
     * @param  mixed  $value
     * @return int
     */
    private function countValueItems(FilterOperator $handler, mixed $value): int
    {
        if (is_array($value)) {
            return count($value);
        }

        return $handler instanceof ExpandsValueList ? $handler->countValueItems($value) : 1;
    }

    /**
     * Apply a logical group, each of its documents grouped on its own.
     *
     * The group attaches with AND, as its parent is always a document.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $operator
     * @param  mixed  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyGroup(Builder $query, string $operator, mixed $value, FilterContext $context): void
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {

            $position = $context->at($operator)->path();

            throw Rejection::at($position, 'filters.not_group', ['position' => $position]);
        }

        $nested  = $context->descend($operator);
        $boolean = $this->logicalOperatorMap[$operator];

        $query->where(function (Builder $group) use ($value, $nested, $boolean): void {
            foreach ($value as $index => $document) {

                $child = $nested->at((string) $index);

                if ($document === []) {
                    throw Rejection::at($child->path(), 'filters.empty_document', ['position' => $child->path()]);
                }

                $document = $this->assertDocument($document, $child->path());

                $group->where(function (Builder $query) use ($document, $child): void {
                    $this->applyDocument($query, $document, $child);
                }, boolean: $boolean);
            }
        });
    }

    /**
     * Apply a $has or $hasnt relation existence filter.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $operator
     * @param  mixed  $value
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyHasFilter(Builder $query, string $operator, mixed $value, FilterContext $context): void
    {
        $method = $this->relationalMethodMap[$operator];
        $scope  = $context->at($operator);

        foreach ($this->relationEntries($value, $scope->path()) as $relation => $filters) {

            $scope->admit((string) $relation);

            if (is_int($relation)) {

                $relation = $this->assertRelationName($filters, $scope->at((string) $relation)->path());

                $this->querySurface->guardRelation($relation, $query->getModel());

                $this->applyRelationalMethod($query, $method, $relation);

                continue;
            }

            $this->querySurface->guardRelation($relation, $query->getModel());

            $this->applyRelation($query, $method, $relation, $this->assertDocument($filters, $scope->at($relation)->path()), $scope);
        }
    }

    /**
     * Apply a relation body as a document on the related resource.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $method
     * @param  string  $relation
     * @param  array<array-key, mixed>  $document
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function applyRelation(Builder $query, string $method, string $relation, array $document, FilterContext $context): void
    {
        $nested = $context->descend($relation);

        $this->applyRelationalMethod($query, $method, $relation, function (Builder $query) use ($document, $nested): void {
            $this->applyDocument($query, $document, $nested);
        });
    }

    /**
     * Invoke the relational existence method on the query.
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
        if ($method === 'whereDoesntHave') {
            $query->whereDoesntHave($relation, $callback);
        } else {
            $query->whereHas($relation, $callback);
        }
    }

    /**
     * Group the clauses a condition added when any of them is OR-joined.
     *
     * Keeps a handler's OR from disjoining the clauses beside it, such as the
     * correlation predicate of a relation subquery.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  int  $applied
     * @return void
     */
    private function isolateCondition(QueryBuilder $query, int $applied): void
    {
        $added = array_slice($query->wheres, $applied);

        $disjoined = array_filter($added, static fn (array $where): bool => in_array($where['boolean'], ['or', 'or not'], true));

        if ($disjoined === []) {
            return;
        }

        $group         = $query->forNestedWhere();
        $group->wheres = $added;

        $query->wheres = [...array_slice($query->wheres, 0, $applied), ['type' => 'Nested', 'query' => $group, 'boolean' => 'and']];
    }

    /**
     * Assert that a document entry or condition added a clause to the query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  int  $applied
     * @param  string  $position
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function assertNarrowed(Builder $query, int $applied, string $position): void
    {
        if (count($query->getQuery()->wheres) <= $applied) {
            throw Rejection::at($position, 'filters.no_effect', ['position' => $position]);
        }
    }

    /**
     * Assert that a document position carries a named key, not a list index.
     *
     * @param  mixed  $key
     * @param  string  $position
     * @return string
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function assertNamedKey(mixed $key, string $position): string
    {
        if (is_string($key)) {
            return $key;
        }

        throw Rejection::at($position, 'filters.unnamed_key', ['position' => $position]);
    }

    /**
     * Assert that a filter position carries a document.
     *
     * @param  mixed  $value
     * @param  string  $position
     * @return array<array-key, mixed>
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function assertDocument(mixed $value, string $position): array
    {
        if (is_array($value) && ($value === [] || !array_is_list($value))) {
            return $value;
        }

        throw Rejection::at($position, 'filters.not_document', ['position' => $position]);
    }

    /**
     * Return the entries of a relation existence filter, a bare name as one.
     *
     * @param  mixed  $value
     * @param  string  $position
     * @return array<array-key, mixed>
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function relationEntries(mixed $value, string $position): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (is_array($value)) {
            return $value;
        }

        throw Rejection::at($position, 'filters.not_document', ['position' => $position]);
    }

    /**
     * Assert that a relation existence filter lists a relation name.
     *
     * @param  mixed  $relation
     * @param  string  $position
     * @return string
     *
     * @throws \SineMacula\ApiToolkit\Validation\Rejection
     */
    private function assertRelationName(mixed $relation, string $position): string
    {
        if (is_string($relation)) {
            return $relation;
        }

        throw Rejection::at($position, 'filters.relation_names', ['position' => $position]);
    }
}
