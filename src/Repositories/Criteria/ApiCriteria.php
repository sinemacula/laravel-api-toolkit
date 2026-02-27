<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\AppliesFilterConditions;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ResolvesSearchableColumns;
use SineMacula\ApiToolkit\Repositories\Traits\InteractsWithModelSchema;
use SineMacula\ApiToolkit\Repositories\Traits\ResolvesResource;
use SineMacula\Repositories\Contracts\CriteriaInterface;

/**
 * API criteria.
 *
 * This class is responsible for applying filters, ordering, and limiting
 * on model queries based on API requests.
 *
 * @SuppressWarnings("php:S1068") Private fields are accessed by traits via $this
 * @SuppressWarnings("php:S1144") Private methods are called by traits via $this
 * @SuppressWarnings("php:S1448") Criteria class necessarily combines filtering, ordering, and eager-loading concerns
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
/** @implements \SineMacula\Repositories\Contracts\CriteriaInterface<\Illuminate\Database\Eloquent\Model> */
class ApiCriteria implements CriteriaInterface
{
    use AppliesFilterConditions, InteractsWithModelSchema, ResolvesResource, ResolvesSearchableColumns;

    /** @var string The column name to be used when ordering items randomly */
    public const string ORDER_BY_RANDOM = 'random';

    /** @var string The filter operator for "does not have relation" */
    private const string OPERATOR_HAS_NOT = '$hasnt';

    /** @var array<string, string> Map of API filter operators to SQL/Eloquent equivalents. */
    private array $conditionOperatorMap = [
        '$le'                  => '<=',
        '$lt'                  => '<',
        '$ge'                  => '>=',
        '$gt'                  => '>',
        '$neq'                 => '<>',
        '$eq'                  => '=',
        '$like'                => 'like',
        '$in'                  => 'in',
        '$between'             => 'between',
        '$contains'            => 'contains',
        '$has'                 => 'has',
        self::OPERATOR_HAS_NOT => 'hasnt',
        '$null'                => 'null',
        '$notNull'             => 'notNull',
    ];

    /** @var array<string, string> Map of API logical operators to Eloquent where methods. */
    private array $logicalOperatorMap = [
        '$or'  => 'orWhere',
        '$and' => 'where',
    ];

    /** @var array<int, string> Valid order directions. */
    private array $directions = ['asc', 'desc'];

    /** @var array<string, array<int, string>> Resolved searchable columns keyed by model class. */
    private array $searchable = [];

    /** @var array<string, string> Map of relational operators to Eloquent where-has methods. */
    private array $relationalMethodMap = [
        '$has'                 => 'whereHas',
        self::OPERATOR_HAS_NOT => 'whereDoesntHave',
    ];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function __construct(

        /** The HTTP request */
        protected Request $request,

    ) {}

    /**
     * Apply the criteria to the given model.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    #[\Override]
    public function apply(Builder|Model $model): Builder
    {
        /** @phpstan-ignore staticMethod.dynamicCall (calling static method on instance is valid PHP) */
        $query = $model instanceof Model ? $model->query() : $model;

        $query = $this->applyFilters($query, $this->getFilters());
        $query = $this->applyEagerLoading($query);
        $query = $this->applyLimit($query, $this->getLimit());

        return $this->applyOrder($query, $this->getOrder());
    }

    /**
     * Apply the filters to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  array<int|string, mixed>|string|null  $filters
     * @param  string|null  $field
     * @param  string|null  $lastLogicalOperator
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    protected function applyFilters(Builder $query, array|string|null $filters = null, ?string $field = null, ?string $lastLogicalOperator = null): Builder
    {
        if (empty($filters)) {
            return $query;
        }

        if (is_string($filters)) {
            return $this->applySimpleFilter($query, $field, $filters, $lastLogicalOperator ?? '$and');
        }

        foreach ($filters as $key => $value) {

            if ($this->isConditionOperator($key)) {
                $this->applyConditionOperator($query, $key, $value, $field, $lastLogicalOperator);
            } elseif ($this->isLogicalOperator($key)) {
                $this->applyLogicalOperator($query, $key, $value, $lastLogicalOperator);
            } elseif ($this->isRelation($key, $query->getModel())) {
                $this->applyRelationFilter($query, $key, $value, $lastLogicalOperator);
            } else {
                $this->applyFilters($query, $value, $key, $lastLogicalOperator);
            }
        }

        return $query;
    }

    /**
     * Apply eager loading based on the mapped ApiResource schema.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    protected function applyEagerLoading(Builder $query): Builder
    {
        $model    = $query->getModel();
        $resource = $this->resolveResource($model);

        if (!$resource || !is_subclass_of($resource, ApiResource::class)) {
            return $query;
        }

        $fields = in_array(':all', ApiQuery::getFields($resource::getResourceType()) ?? [], true)
            ? $resource::getAllFields()
            : $resource::resolveFields();

        if (empty($fields)) {
            return $query;
        }

        $with = $resource::eagerLoadMapFor($fields);

        if (!empty($with)) {
            $query->with($with);
        }

        $requestedCounts = ApiQuery::getCounts($resource::getResourceType()) ?? [];
        $withCounts      = $resource::eagerLoadCountsFor($requestedCounts);

        if (!empty($withCounts)) {
            $query->withCount($withCounts);
        }

        return $query;
    }

    /**
     * Append the query with a record limit.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  int|null  $limit
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    protected function applyLimit(Builder $query, ?int $limit = null): Builder
    {
        if (is_null($limit)) {
            return $query;
        }

        /** @phpstan-ignore return.type, staticMethod.dynamicCall (limit() is provided by Eloquent Builder and returns the correct type at runtime) */
        return $query->limit($limit);
    }

    /**
     * Append an order by statement to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  array<string, string>  $order
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    protected function applyOrder(Builder $query, array $order): Builder
    {
        if (empty($order)) {
            return $query;
        }

        foreach ($order as $column => $direction) {

            if ($column === self::ORDER_BY_RANDOM) {
                $query = $query->inRandomOrder();
                continue;
            }

            // @phpstan-ignore method.notFound ($getModel() is provided by Eloquent Builder)
            if ($this->isColumnSearchable($query->getModel(), $column, $direction)) {
                $query = $query->orderBy($column, $direction);
            }
        }

        /** @phpstan-ignore return.type (inRandomOrder() returns the correct Builder type at runtime) */
        return $query;
    }

    /**
     * Check if a column is searchable and direction is valid.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @param  string  $direction
     * @return bool
     */
    private function isColumnSearchable(Model $model, string $column, string $direction): bool
    {
        return in_array($column, $this->getSearchableColumns($model), true)
            && in_array($direction, $this->directions, true);
    }

    /**
     * Get the filters to be applied to the query.
     *
     * @return array<int|string, mixed>|null
     */
    private function getFilters(): ?array
    {
        return ApiQuery::getFilters();
    }

    /**
     * Get the limit to be applied to the query.
     *
     * @return int|null
     */
    private function getLimit(): ?int
    {
        return ApiQuery::getLimit();
    }

    /**
     * Get the order to be applied to the query.
     *
     * @return array<string, string>
     */
    private function getOrder(): array
    {
        return ApiQuery::getOrder();
    }

    /**
     * Apply a simple filter to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string|null  $column
     * @param  string  $value
     * @param  string  $logicalOperator
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    private function applySimpleFilter(Builder $query, ?string $column, string $value, string $logicalOperator): Builder
    {
        if ($column && in_array($column, $this->getSearchableColumns($query->getModel()), true)) {
            $value = $this->formatValueBasedOnOperator($value, $logicalOperator);
            // @phpstan-ignore method.dynamicName (method name is resolved from logicalOperatorMap)
            $query->{$this->logicalOperatorMap[$logicalOperator]}($column, $value);
        }

        return $query;
    }

    /**
     * Apply filters for relational queries.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $relation
     * @param  array<int|string, mixed>  $filters
     * @param  string|null  $lastLogicalOperator
     * @return void
     */
    private function applyRelationFilter(Builder $query, string $relation, array $filters, ?string $lastLogicalOperator): void
    {
        $method = ($lastLogicalOperator === '$or') ? 'orWhereHas' : 'whereHas';

        // @phpstan-ignore method.dynamicName (method name is dynamically resolved to whereHas/orWhereHas)
        $query->{$method}($relation, function (Builder $query) use ($filters): void {
            $this->processRelationFilters($query, $filters);
        });
    }

    /**
     * Process relation filters.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  array<int|string, mixed>  $filters
     * @return void
     */
    private function processRelationFilters(Builder $query, array $filters): void
    {
        if (isset($filters['$or'])) {

            $query->where(function ($nested) use ($filters): void {
                // @phpstan-ignore foreach.nonIterable (filters[$or] is always an iterable array at this point)
                foreach ($filters['$or'] as $key => $value) {
                    $this->applyFilters($nested, $value, $key, '$or');
                }
            });
        } else {
            foreach ($filters as $key => $value) {
                $this->applyFilters($query, $value, $key);
            }
        }
    }

    /**
     * Apply a whereHas or whereDoesntHave filter.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  array<int|string, mixed>|string  $relations
     * @param  string  $operator
     * @param  string|null  $lastLogicalOperator
     * @return void
     */
    private function applyHasFilter(Builder $query, array|string $relations, string $operator, ?string $lastLogicalOperator = null): void
    {
        $baseMethod = $this->relationalMethodMap[$operator];
        $method     = match (true) {
            $lastLogicalOperator === '$or' && $operator === '$has'                 => 'orWhereHas',
            $lastLogicalOperator === '$or' && $operator === self::OPERATOR_HAS_NOT => 'orWhereDoesntHave',
            default                                                                => $baseMethod,
        };

        foreach ((array) $relations as $relation => $filters) {

            if (is_int($relation)) {
                $this->applySimpleHasFilter($query, $method, $filters);
            } else {
                $this->applyNestedHasFilter($query, $method, $relation, $filters);
            }
        }
    }

    /**
     * Apply a simple has/doesntHave filter for a relation without nested filters.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $method
     * @param  string  $relation
     * @return void
     */
    private function applySimpleHasFilter(Builder $query, string $method, string $relation): void
    {
        if ($this->isRelation($relation, $query->getModel())) {
            // @phpstan-ignore method.dynamicName (method name is dynamically resolved to whereHas/whereDoesntHave)
            $query->{$method}($relation);
        }
    }

    /**
     * Apply a has/doesntHave filter for a relation with nested filters.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $method
     * @param  string  $relation
     * @param  array<int|string, mixed>  $filters
     * @return void
     */
    private function applyNestedHasFilter(Builder $query, string $method, string $relation, array $filters): void
    {
        if ($this->isRelation($relation, $query->getModel())) {
            // @phpstan-ignore method.dynamicName (method name is dynamically resolved to whereHas/whereDoesntHave)
            $query->{$method}($relation, function (Builder $query) use ($filters): void {
                $this->processRelationFilters($query, $filters);
            });
        }
    }

    /**
     * Determine if the given operator is logical.
     *
     * @param  string|null  $operator
     * @return bool
     */
    private function isLogicalOperator(?string $operator = null): bool
    {
        return array_key_exists($operator, $this->logicalOperatorMap);
    }

    /**
     * Determine if a given key is a relation on the given model.
     *
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    private function isRelation(string $key, Model $model): bool
    {
        return Cache::memo()->rememberForever(CacheKeys::MODEL_RELATIONS->resolveKey([
            $model::class,
            $key,
        ]), function () use ($key, $model) {
            if (!method_exists($model, $key) || !is_callable([$model, $key])) {
                return false;
            }

            try {
                // @phpstan-ignore method.dynamicName (existence and callability are verified by method_exists and is_callable guards above)
                return $model->{$key}() instanceof Relation;
            } catch (\BadMethodCallException $e) {
                return false;
            }
        });
    }
}
