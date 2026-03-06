<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Repositories\Traits\InteractsWithModelSchema;
use SineMacula\ApiToolkit\Repositories\Traits\ResolvesResource;
use SineMacula\Repositories\Contracts\CriteriaInterface;

/**
 * API criteria.
 *
 * This class is responsible for applying filters, ordering, and limiting on
 * model queries based on API requests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class ApiCriteria implements CriteriaInterface
{
    use InteractsWithModelSchema, ResolvesResource;

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
        '$hasnt'    => 'hasnt',
        '$null'     => 'null',
        '$notNull'  => 'notNull',
    ];

    /** @var array<string, string> */
    private array $logicalOperatorMap = [
        '$or'  => 'orWhere',
        '$and' => 'where',
    ];

    /** @var array<string, string> */
    private array $relationLogicalOperatorMap = [
        '$or'  => 'orWhereHas',
        '$and' => 'whereHas',
    ];

    /** @var array<int, string> */
    private array $directions = ['asc', 'desc'];

    /** @var array<string, array<int, string>> */
    private array $searchable = [];

    /** @var array<string, string> */
    private array $relationalMethodMap = [
        '$has'   => 'whereHas',
        '$hasnt' => 'whereDoesntHave',
    ];

    /**
     * Constructor.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider  $metadataProvider
     */
    public function __construct(

        /** The HTTP request */
        protected Request $request,

        /** The resource metadata provider */
        private readonly ResourceMetadataProvider $metadataProvider,

    ) {}

    /**
     * Apply the criteria to the given model.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function apply(Builder|Model $model): Builder
    {
        $query = $model instanceof Model ? $model->query() : $model;

        $query = $this->applyFilters($query, $this->getFilters());
        $query = $this->applyEagerLoading($query);
        $query = $this->applyLimit($query, $this->getLimit());

        return $this->applyOrder($query, $this->getOrder());
    }

    /**
     * Apply the filters to the query.
     *
     * This appends the supplied query with the requested filters.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array|string|null  $filters
     * @param  string|null  $field
     * @param  string|null  $lastLogicalOperator
     * @return \Illuminate\Database\Eloquent\Builder
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

        $resourceType = $this->metadataProvider->getResourceType($resource);

        $fields = in_array(':all', ApiQuery::getFields($resourceType) ?? [], true)
            ? $this->metadataProvider->getAllFields($resource)
            : $this->metadataProvider->resolveFields($resource);

        if (empty($fields)) {
            return $query;
        }

        $with = $this->metadataProvider->eagerLoadMapFor($resource, $fields);

        if (!empty($with)) {
            $query->with($with);
        }

        $requestedCounts = ApiQuery::getCounts($resourceType) ?? [];
        $withCounts      = $this->metadataProvider->eagerLoadCountsFor($resource, $requestedCounts);

        if (!empty($withCounts)) {
            $query->withCount($withCounts);
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
        if (empty($order)) {
            return $query;
        }

        foreach ($order as $column => $direction) {
            if ($column === self::ORDER_BY_RANDOM) {
                $query = $query->inRandomOrder();
                continue;
            }

            if ($this->isColumnSearchable($query->getModel(), $column, $direction)) {
                $query = $query->orderBy($column, $direction);
            }
        }

        return $query;
    }

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
        if (in_array($operator, ['$has', '$hasnt'], true)) {
            $this->applyHasFilter($query, $value, $operator, $lastLogicalOperator);
        } else {
            $this->handleCondition($query, $operator, $value, $field, $lastLogicalOperator);
        }
    }

    /**
     * Apply a logical operator to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $operator
     * @param  array  $value
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
     * @return array<string, mixed>|null
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
     * @param  string  $logicalOperator
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    private function applySimpleFilter(Builder $query, ?string $column, string $value, string $logicalOperator): Builder
    {
        if ($column && in_array($column, $this->getSearchableColumns($query->getModel()), true)) {
            $value = $this->formatValueBasedOnOperator($value, $logicalOperator);
            $query->{$this->logicalOperatorMap[$logicalOperator]}($column, $value);
        }

        return $query;
    }

    /**
     * Apply filters for relational queries.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $relation
     * @param  array  $filters
     * @param  string|null  $lastLogicalOperator
     * @return void
     */
    private function applyRelationFilter(Builder $query, string $relation, array $filters, ?string $lastLogicalOperator): void
    {
        $method = ($lastLogicalOperator === '$or') ? 'orWhereHas' : 'whereHas';

        $query->{$method}($relation, function (Builder $query) use ($filters): void {
            $this->processRelationFilters($query, $filters);
        });
    }

    /**
     * Process relation filters.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  array  $filters
     * @return void
     */
    private function processRelationFilters(Builder $query, array $filters): void
    {
        if (isset($filters['$or'])) {
            $query->where(function ($nested) use ($filters): void {
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
     * @param  array|string  $relations
     * @param  string  $operator
     * @param  string|null  $lastLogicalOperator
     * @return void
     */
    private function applyHasFilter(Builder $query, array|string $relations, string $operator, ?string $lastLogicalOperator = null): void
    {
        $baseMethod = $this->relationalMethodMap[$operator];
        $method      = ($lastLogicalOperator === '$or' && $operator === '$has') ? 'orWhereHas' : $baseMethod;

        foreach ((array) $relations as $relation => $filters) {
            if (is_int($relation)) {
                if ($this->isRelation($filters, $query->getModel())) {
                    $query->{$method}($filters);
                }
            } else {
                if ($this->isRelation($relation, $query->getModel())) {
                    $query->{$method}($relation, function (Builder $query) use ($filters): void {
                        $this->processRelationFilters($query, $filters);
                    });
                }
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
        return array_key_exists($operator, $this->conditionOperatorMap);
    }

    /**
     * Handle a condition based on its operator.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $operator
     * @param  mixed  $value
     * @param  string|null  $column
     * @param  string|null  $lastLogicalOperator
     * @return void
     */
    private function handleCondition(Builder $query, string $operator, mixed $value, ?string $column, ?string $lastLogicalOperator): void
    {
        if (!$column || !in_array($column, $this->getSearchableColumns($query->getModel()), true)) {
            return;
        }

        match ($operator) {
            '$in'       => $query->whereIn($column, (array) $value),
            '$between'  => $this->applyBetween($query, $column, $value),
            '$contains' => $this->applyJsonContains($query, $column, $value),
            '$null'     => $this->applyNullCondition($query, $column, true, $lastLogicalOperator),
            '$notNull'  => $this->applyNullCondition($query, $column, false, $lastLogicalOperator),
            default     => $this->applyDefaultCondition($query, $column, $operator, $value, $lastLogicalOperator),
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

            $items = array_filter(array_map('trim', explode(',', $value)));

            if (!empty($items)) {
                $query->where(function (Builder $query) use ($column, $items): void {
                    foreach ($items as $index => $item) {
                        $method = $index === 0 ? 'whereJsonContains' : 'orWhereJsonContains';
                        $query->{$method}($column, $item);
                    }
                });
            }

            return;
        }

        try {
            $query->whereJsonContains($column, $value);
        } catch (\Throwable $exception) { // @codeCoverageIgnore
            // Intentionally swallowed: non-JSON-castable scalar values may
            // cause a database driver error; silently skipping the filter is
            // preferred over failing the entire request.
        } // @codeCoverageIgnore
    }

    /**
     * Check if a string is valid JSON.
     *
     * @param  string|null  $string
     * @return bool
     */
    private function isValidJson(?string $string): bool
    {
        if (!is_string($string) || empty($string)) {
            return false;
        }

        return json_validate($string);
    }

    /**
     * Apply a null or not null condition to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $column
     * @param  bool  $isNull
     * @param  string|null  $lastLogicalOperator
     * @return void
     */
    private function applyNullCondition(Builder $query, string $column, bool $isNull, ?string $lastLogicalOperator): void
    {
        $method = $this->logicalOperatorMap[$lastLogicalOperator ?? '$and'];

        if ($isNull) {
            $query->{$method . 'Null'}($column);
        } else {
            $query->{$method . 'NotNull'}($column);
        }
    }

    /**
     * Apply a default condition to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $column
     * @param  string  $operator
     * @param  mixed  $value
     * @param  string|null  $lastLogicalOperator
     * @return void
     */
    private function applyDefaultCondition(Builder $query, string $column, string $operator, mixed $value, ?string $lastLogicalOperator): void
    {
        $method          = $this->logicalOperatorMap[$lastLogicalOperator ?? '$and'];
        $sqlOperator    = $this->conditionOperatorMap[$operator];
        $formattedValue = $this->formatValueBasedOnOperator($value, $operator);

        $query->{$method}($column, $sqlOperator, $formattedValue);
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
                return $model->{$key}() instanceof Relation;
            } catch (\Throwable $e) {
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
        $class = $model::class;

        if (!isset($this->searchable[$class])) {
            $this->searchable[$class] = $this->resolveSearchableColumns($model);
        }

        return $this->searchable[$class];
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

    /**
     * Resolve the searchable columns for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array
     */
    private function resolveSearchableColumns(Model $model): array
    {
        $table      = $model->getTable();
        $exclusions = $this->getColumnExclusions($table);

        return array_diff($this->getColumnsFromModel($model), $exclusions);
    }

    /**
     * Get column exclusions for the given table.
     *
     * @param  string  $table
     * @return array
     */
    private function getColumnExclusions(string $table): array
    {
        return (array) collect(Config::get('api-toolkit.repositories.searchable_exclusions', []))
            ->reduce(function ($carry, $exclusion) use ($table) {

                if (str_contains($exclusion, '.') && strtok($exclusion, '.') === $table) {
                    $carry[] = substr(strstr($exclusion, '.'), 1);
                } else {
                    $carry[] = $exclusion;
                }

                return $carry;
            }, []);
    }
}
