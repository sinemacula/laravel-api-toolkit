<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Traits\InteractsWithModelSchema;
use SineMacula\Repositories\Contracts\CriteriaInterface;
use Throwable;

/**
 * API criteria.
 *
 * This class is responsible for applying filters, ordering, and limiting
 * on model queries based on API requests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class ApiCriteria implements CriteriaInterface
{
    use InteractsWithModelSchema;

    /** @var string The column name to be used when ordering items randomly */
    public const string ORDER_BY_RANDOM = 'random';

    /** @var array<string, string> */
    private array $conditionOperatorMap = [
        '$le'       => '<=',
        '$lt'       => '<',
        '$ge'       => '>=',
        '$gt'       => '>',
        '$neq'      => '<>',
        '$eq'       => '=',
        '$like'     => 'like',
        '$in'       => 'in',
        '$between'  => 'between',
        '$contains' => 'contains',
        '$has'      => 'has',
        '$hasnt'    => 'hasnt'
    ];

    /** @var array<string, string> */
    private array $logicalOperatorMap = [
        '$or'  => 'orWhere',
        '$and' => 'where'
    ];

    /** @var array<string, string> */
    private array $relationLogicalOperatorMap = [
        '$or'  => 'orWhereHas',
        '$and' => 'whereHas'
    ];

    /** @var array<int, string> */
    private array $directions = ['asc', 'desc'];

    /** @var array<string, array> */
    private array $searchable = [];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function __construct(

        /** The HTTP request */
        protected Request $request

    ) {}

    /**
     * Apply the criteria to the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model|\Illuminate\Contracts\Database\Eloquent\Builder  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function apply(Model|Builder $model): Builder
    {
        $query = $model instanceof Model ? $model->query() : $model;

        $query = $this->applyFilters($query, $this->getFilters());
        $query = $this->applyLimit($query, $this->getLimit());
        $query = $this->applyOrder($query, $this->getOrder());

        return $query;
    }

    /**
     * Apply the filters to the query.
     *
     * This appends the supplied query with the requested filters.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array|string|null  $filters
     * @param  string|null  $field
     * @param  string|null  $last_logical_operator
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyFilters(Builder $query, array|string|null $filters = null, ?string $field = null, ?string $last_logical_operator = null): Builder
    {
        if (is_string($filters)) {
            return $this->applySimpleFilter($query, $field, $filters, $last_logical_operator ?? '$and');
        }

        foreach ($filters as $key => $value) {

            if ($this->isConditionOperator($key)) {
                if (in_array($key, ['$has', '$hasnt'])) {
                    $this->applyHasFilter($query, $value, $key, $last_logical_operator);
                } else {
                    $this->handleCondition($query, $key, $value, $field, $last_logical_operator);
                }
                continue;
            }

            if ($this->isLogicalOperator($key)) {

                if ($key === '$and') {
                    $query->where(function ($q) use ($value) {
                        foreach ($value as $subKey => $subValue) {
                            $this->applyFilters($q, $subValue, $subKey, '$and');
                        }
                    });
                } elseif ($key === '$or') {
                    if ($last_logical_operator === '$and') {
                        $query->where(function ($q) use ($value) {
                            $q->orWhere(function ($orQ) use ($value) {
                                foreach ($value as $subKey => $subValue) {
                                    $this->applyFilters($orQ, $subValue, $subKey, '$or');
                                }
                            });
                        });
                    } else {]
                        $query->orWhere(function ($q) use ($value) {
                            foreach ($value as $subKey => $subValue) {
                                $this->applyFilters($q, $subValue, $subKey, '$or');
                            }
                        });
                    }
                }

                continue;
            }

            if ($this->isRelation($key, $query->getModel())) {
                $this->applyRelationFilter($query, $key, $value, $last_logical_operator);
            } else {
                $this->applyFilters($query, $value, $key, $last_logical_operator);
            }
        }

        return $query;
    }

    /**
     * Apply limit.
     *
     * Append the query with a record limit.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  int|null  $limit
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    protected function applyLimit(Builder $query, ?int $limit = null): Builder
    {
        return is_null($limit) ? $query : $query->limit($limit);
    }

    /**
     * Apply order.
     *
     * Append an order by statement to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  array  $order
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    protected function applyOrder(Builder $query, array $order): Builder
    {
        if (!$order) {
            return $query;
        }

        foreach ($order as $column => $direction) {

            if ($column === self::ORDER_BY_RANDOM) {
                $query = $query->inRandomOrder();
                continue;
            }

            if (in_array($column, $this->getSearchableColumns($query->getModel())) && in_array($direction, $this->directions)) {
                $query = $query->orderBy($column, $direction);
            }
        }

        return $query;
    }

    /**
     * Get the filters to be applied to the query.
     *
     * @return array|null
     */
    private function getFilters(): ?array
    {
        return ApiQuery::getFilters();
    }

    /**
     * Get the limit to be applied to the query.
     *
     * @return int
     */
    private function getLimit(): int
    {
        return ApiQuery::getLimit();
    }

    /**
     * Get the order to be applied to the query.
     *
     * @return array
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
     * @param  string  $logical_operator
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    private function applySimpleFilter(Builder $query, ?string $column, string $value, string $logical_operator): Builder
    {
        if ($column && in_array($column, $this->getSearchableColumns($query->getModel()))) {

            $value = $this->formatValueBasedOnOperator($value, $logical_operator);

            $query->{$this->logicalOperatorMap[$logical_operator]}($column, $value);
        }

        return $query;
    }

    /**
     * Apply filters for relational queries.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $relation
     * @param  array  $filters
     * @param  string|null  $last_logical_operator
     * @return void
     */
    private function applyRelationFilter(Builder $query, string $relation, array $filters, ?string $last_logical_operator): void
    {
        $method = ($last_logical_operator === '$or') ? 'orWhereHas' : 'whereHas';

        $query->{$method}($relation, function ($q) use ($filters) {
            if (isset($filters['$or'])) {
                $q->where(function ($nested) use ($filters) {
                    foreach ($filters['$or'] as $key => $value) {
                        $this->applyFilters($nested, $value, $key, '$or');
                    }
                });
            } else {
                foreach ($filters as $key => $value) {
                    $this->applyFilters($q, $value, $key);
                }
            }
        });
    }

    /**
     * Apply a whereHas or whereDoesntHave filter.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  array|string  $relations
     * @param  string  $operator
     * @return void
     */
    private function applyHasFilter(Builder $query, array|string $relations, string $operator, ?string $last_logical_operator = null): void
    {
        $method = ($operator === '$hasnt') ? 'whereDoesntHave' : ($last_logical_operator === '$or' ? 'orWhereHas' : 'whereHas');

        foreach ((array) $relations as $relation => $filters) {
            if (is_int($relation)) {
                $query->{$method}($filters);
            } else {
                $query->{$method}($relation, function ($q) use ($filters) {
                    if (isset($filters['$or'])) {
                        $q->where(function ($nested) use ($filters) {
                            foreach ($filters['$or'] as $key => $value) {
                                $this->applyFilters($nested, $value, $key, '$or');
                            }
                        });
                    } else {
                        foreach ($filters as $key => $value) {
                            $this->applyFilters($q, $value, $key);
                        }
                    }
                });
            }
        }
    }

    /**
     * Determine if the given operator is conditional.
     *
     * @param  string|null  $operator
     * @return bool
     */
    private function isConditionOperator(?string $operator = null): bool
    {
        return in_array($operator, array_keys($this->conditionOperatorMap));
    }

    /**
     * Handle a condition based on its operator.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $operator
     * @param  mixed  $value
     * @param  string|null  $column
     * @param  string|null  $last_logical_operator
     * @return void
     */
    private function handleCondition(Builder $query, string $operator, mixed $value, ?string $column, ?string $last_logical_operator): void
    {
        if (!$column || !in_array($column, $this->getSearchableColumns($query->getModel()))) {
            return;
        }

        match ($operator) {
            '$in'       => $query->whereIn($column, (array) $value),
            '$between'  => $this->applyBetween($query, $column, $value),
            '$contains' => $query->whereJsonContains($column, $value),
            default     => $query->{$this->logicalOperatorMap[$last_logical_operator ?? '$and']}($column, $this->conditionOperatorMap[$operator], $this->formatValueBasedOnOperator($value, $operator))
        };
    }

    /**
     * Determine if the given operator is logical.
     *
     * @param  string|null  $operator
     * @return bool
     */
    private function isLogicalOperator(?string $operator = null): bool
    {
        return in_array($operator, array_keys($this->logicalOperatorMap));
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
        return Cache::rememberForever(CacheKeys::MODEL_RELATIONS->resolveKey([
            get_class($model),
            $key
        ]), function () use ($key, $model) {

            if (!method_exists($model, $key) || !is_callable([$model, $key])) {
                return false;
            }

            try {
                return $model->{$key}() instanceof Relation;
            } catch (Throwable $e) {
                return false;
            }

        });
    }

    /**
     * Get the searchable columns for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array
     */
    private function getSearchableColumns(Model $model): array
    {
        return $this->searchable[get_class($model)] ??= $this->resolveSearchableColumns($model);
    }

    /**
     * Format the value based on the specified operator.
     *
     * @param  mixed  $value
     * @param  string  $operator
     * @return string
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

    /**
     * Resolve the searchable columns for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array
     */
    private function resolveSearchableColumns(Model $model): array
    {
        $table = $model->getTable();

        $exclusions = collect(Config::get('api-toolkit.repositories.searchable_exclusions', []))
            ->reduce(function ($carry, $exclusion) use ($table) {

                if (str_contains($exclusion, '.') && strtok($exclusion, '.') === $table) {
                    $carry[] = substr(strstr($exclusion, '.'), 1);
                } else {
                    $carry[] = $exclusion;
                }

                return $carry;
            }, []);

        return array_diff($this->getColumnsFromModel($model), $exclusions);
    }
}
