<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
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
        '$hasnt'    => 'hasnt',
        '$null'     => 'null',
        '$notNull'  => 'notNull'
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

    /** @var array<string, string> */
    private array $relationalMethodMap = [
        '$has'   => 'whereHas',
        '$hasnt' => 'whereDoesntHave'
    ];

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

        if (config('api-toolkit.parser.enable_eager_loading')) {
            $query = $this->applyEagerLoading($query);
        }

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
        if (empty($filters)) {
            return $query;
        }

        if (is_string($filters)) {
            return $this->applySimpleFilter($query, $field, $filters, $last_logical_operator ?? '$and');
        }

        foreach ($filters as $key => $value) {
            if ($this->isConditionOperator($key)) {
                $this->applyConditionOperator($query, $key, $value, $field, $last_logical_operator);
            } elseif ($this->isLogicalOperator($key)) {
                $this->applyLogicalOperator($query, $key, $value, $last_logical_operator);
            } elseif ($this->isRelation($key, $query->getModel())) {
                $this->applyRelationFilter($query, $key, $value, $last_logical_operator);
            } else {
                $this->applyFilters($query, $value, $key, $last_logical_operator);
            }
        }

        return $query;
    }

    /**
     * Apply eager loading based on requested fields.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    protected function applyEagerLoading(Builder $query): Builder
    {
        $model = $query->getModel();
        $resource_class = $this->getResourceFromModel($model);
        if (!$resource_class) {
            return $query;
        }

        // Get eager load config repo from container with fallback
        try {
            $config_repo = app(\SineMacula\ApiToolkit\Repositories\Contracts\EagerLoadConfigRepositoryInterface::class);
            $relations = $config_repo->getRelationsFor($resource_class);
        } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
            // Fallback configuration if provider is not loaded
            $relations = $this->getFallbackEagerLoadConfig($resource_class);
        }
        
        if (empty($relations)) {
            return $query;
        }

        // Get resolvers from container
        $resolvers = app('relation.resolvers', []);
        $eloquent_relations = [];

        foreach ($relations as $relation) {
            // Handle array config (like settings with 'with' relations)
            if (is_array($relation)) {
                $relation_name = $relation['relation'] ?? null;
                if (!$relation_name) {
                    continue;
                }

                // Try to resolve via a registered resolver first
                $resolved = false;
                foreach ($resolvers as $resolver) {
                    if ($resolver instanceof \SineMacula\ApiToolkit\Repositories\Contracts\RelationResolverInterface && $resolver->supports($relation_name, $model)) {
                        $resolver->resolve($relation_name, $model);
                        $resolved = true;
                        break;
                    }
                }

                // If not resolved by resolver, check if it's a standard Eloquent relation
                if (!$resolved && method_exists($model, $relation_name)) {
                    try {
                        $eloquent_relation = $model->{$relation_name}();
                        if ($eloquent_relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            // Handle MorphTo relations specially
                            if ($eloquent_relation instanceof MorphTo) {
                                // MorphTo relations need special handling - add without nested relations
                                $eloquent_relations[] = $relation_name;
                            } else {
                                // Regular relation - can handle 'with' sub-relations
                                $with_relations = $relation['with'] ?? [];
                                if (!empty($with_relations)) {
                                    $eloquent_relations[$relation_name] = function($query) use ($with_relations) {
                                        $query->with($with_relations);
                                    };
                                } else {
                                    $eloquent_relations[] = $relation_name;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        // Relation doesn't exist or has issues
                    }
                }
            } else {
                // Handle string relation
                if (method_exists($model, $relation)) {
                    try {
                        $eloquent_relation = $model->{$relation}();
                        if ($eloquent_relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                            // Handle MorphTo relations specially
                            if ($eloquent_relation instanceof MorphTo) {
                                $eloquent_relations[] = $relation;
                            } else {
                                $eloquent_relations[] = $relation;
                            }
                        }
                    } catch (\Throwable $e) {
                        // Relation doesn't exist or has issues
                    }
                } else {
                    // Try to resolve via a registered resolver
                    foreach ($resolvers as $resolver) {
                        if ($resolver instanceof \SineMacula\ApiToolkit\Repositories\Contracts\RelationResolverInterface && $resolver->supports($relation, $model)) {
                            $resolver->resolve($relation, $model);
                            break;
                        }
                    }
                }
            }
        }

        if (!empty($eloquent_relations)) {
            $query->with($eloquent_relations);
        }
        return $query;
    }

    /**
     * Build a serializable structure for eager loading.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array  $fields
     * @param  int  $depth
     * @return array
     */
    protected function buildEagerLoadStructure(Model $model, array $fields, int $depth = 0): array
    {
        if ($depth > 3) {
            return [];
        }

        $structure = [];

        $relation_fields = [];
        foreach ($fields as $field) {
            if ($this->isRelation($field, $model)) {
                try {
                    $relation = $model->{$field}();
                    if (!($relation instanceof MorphTo)) {
                        $relation_fields[] = $field;
                    }
                } catch (Throwable $e) {
                }
            }
        }

        foreach ($relation_fields as $field) {

            $related_model = $this->getRelatedModel($model, $field);

            if (!$related_model) {
                $structure[] = $field;
                continue;
            }

            $resource = $this->getResourceFromModel($related_model);

            if (!$resource) {
                $structure[] = $field;
                continue;
            }

            $resource_type  = $resource::getResourceType();
            $related_fields = ApiQuery::getFields($resource_type) ?? $resource::getDefaultFields();

            if (empty($related_fields)) {
                $structure[] = $field;
                continue;
            }

            $nested_relation_fields = [];

            foreach ($related_fields as $related_field) {
                if ($this->isRelation($related_field, $related_model)) {
                    try {
                        $nested_relation = $related_model->{$related_field}();
                        if (!($nested_relation instanceof MorphTo)) {
                            $nested_relation_fields[] = $related_field;
                        }
                    } catch (Throwable $e) {
                    }
                }
            }

            if (empty($nested_relation_fields)) {
                $structure[] = $field;
                continue;
            }

            $nested_structure = $this->buildEagerLoadStructure(
                $related_model,
                $nested_relation_fields,
                $depth + 1
            );

            if (empty($nested_structure)) {
                $structure[] = $field;
            } else {
                $structure[$field] = $nested_structure;
            }
        }

        return $structure;
    }

    /**
     * Generate eager loads with closures from the structure.
     *
     * @param  array  $structure
     * @param  \Illuminate\Database\Eloquent\Model|null  $parent_model
     * @return array
     */
    protected function generateEagerLoads(array $structure, ?Model $parent_model = null): array
    {
        $eager_loads = [];

        foreach ($structure as $key => $value) {
            if (is_numeric($key)) {
                $eager_loads[] = $value;
            } else {

                $related_model = $parent_model ? $this->getRelatedModel($parent_model, $key) : null;

                $eager_loads[$key] = function ($query) use ($value, $related_model) {
                    if (!empty($value)) {
                        $query->with($this->generateEagerLoads($value, $related_model));
                    }
                };
            }
        }

        return $eager_loads;
    }

    /**
     * Get the eager load structure for the given model and fields.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array  $fields
     * @return array
     */
    protected function getEagerLoadStructure(Model $model, array $fields): array
    {
        return Cache::rememberForever(CacheKeys::MODEL_EAGER_LOADS->resolveKey([
            get_class($model),
            md5(implode(',', array_filter($fields)))
        ]), function () use ($model, $fields) {
            return $this->buildEagerLoadStructure($model, $fields);
        });
    }

    /**
     * Get the resource from the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     */
    protected function getResourceFromModel(Model $model): ?string
    {
        $class = get_class($model);

        return Cache::rememberForever(CacheKeys::MODEL_RESOURCES->resolveKey([$class]), function () use ($class) {

            $resource = Config::get('api-toolkit.resources.resource_map.' . $class);

            if ($resource && class_exists($resource) && in_array(ApiResourceInterface::class, class_implements($resource) ?: [], true)) {
                return $resource;
            }

            return null;
        });
    }

    /**
     * Get the related model instance for a relation.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $relation
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    protected function getRelatedModel(Model $model, string $relation): ?Model
    {
        return Cache::rememberForever(CacheKeys::MODEL_RELATION_INSTANCES->resolveKey([
            get_class($model),
            $relation
        ]), function () use ($model, $relation) {

            try {

                if (!method_exists($model, $relation)) {
                    return null;
                }

                $relation_obj = $model->{$relation}();

                if ($relation_obj instanceof Relation) {
                    return $relation_obj->getRelated();
                }

            } catch (Throwable $exception) {
            }

            return null;
        });
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
     * @param  string|null  $last_logical_operator
     * @return void
     */
    private function applyConditionOperator(Builder $query, string $operator, mixed $value, ?string $field, ?string $last_logical_operator): void
    {
        if (in_array($operator, ['$has', '$hasnt'])) {
            $this->applyHasFilter($query, $value, $operator, $last_logical_operator);
        } else {
            $this->handleCondition($query, $operator, $value, $field, $last_logical_operator);
        }
    }

    /**
     * Apply a logical operator to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string  $operator
     * @param  array  $value
     * @param  string|null  $last_logical_operator
     * @return void
     */
    private function applyLogicalOperator(Builder $query, string $operator, array $value, ?string $last_logical_operator): void
    {
        $method = $this->determineLogicalMethod($operator, $last_logical_operator);

        $query->{$method}(function (Builder $query) use ($value, $operator) {
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
     * @param  string|null  $last_logical_operator
     * @return string
     */
    private function determineLogicalMethod(string $operator, ?string $last_logical_operator): string
    {
        return ($last_logical_operator === '$and' && $operator === '$or')
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
        return in_array($column, $this->getSearchableColumns($model))
            && in_array($direction, $this->directions);
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

        $query->{$method}($relation, function (Builder $query) use ($filters) {
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
            $query->where(function ($nested) use ($filters) {
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
     * @param  string|null  $last_logical_operator
     * @return void
     */
    private function applyHasFilter(Builder $query, array|string $relations, string $operator, ?string $last_logical_operator = null): void
    {
        $base_method = $this->relationalMethodMap[$operator];
        $method      = ($last_logical_operator === '$or' && $operator === '$has') ? 'orWhereHas' : $base_method;

        foreach ((array) $relations as $relation => $filters) {
            if (is_int($relation)) {
                if ($this->isRelation($filters, $query->getModel())) {
                    $query->{$method}($filters);
                }
            } else {
                if ($this->isRelation($relation, $query->getModel())) {
                    $query->{$method}($relation, function (Builder $query) use ($filters) {
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
            '$contains' => $this->applyJsonContains($query, $column, $value),
            '$null'     => $this->applyNullCondition($query, $column, true, $last_logical_operator),
            '$notNull'  => $this->applyNullCondition($query, $column, false, $last_logical_operator),
            default     => $this->applyDefaultCondition($query, $column, $operator, $value, $last_logical_operator)
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
                $query->where(function (Builder $query) use ($column, $items) {
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
        } catch (Throwable $exception) {
        }
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
     * @param  string|null  $last_logical_operator
     * @return void
     */
    private function applyNullCondition(Builder $query, string $column, bool $isNull, ?string $last_logical_operator): void
    {
        $method = $this->logicalOperatorMap[$last_logical_operator ?? '$and'];

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
     * @param  string|null  $last_logical_operator
     * @return void
     */
    private function applyDefaultCondition(Builder $query, string $column, string $operator, mixed $value, ?string $last_logical_operator): void
    {
        $method          = $this->logicalOperatorMap[$last_logical_operator ?? '$and'];
        $sql_operator    = $this->conditionOperatorMap[$operator];
        $formatted_value = $this->formatValueBasedOnOperator($value, $operator);

        $query->{$method}($column, $sql_operator, $formatted_value);
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
        $class = get_class($model);

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
        return collect(Config::get('api-toolkit.repositories.searchable_exclusions', []))
            ->reduce(function ($carry, $exclusion) use ($table) {

                if (str_contains($exclusion, '.') && strtok($exclusion, '.') === $table) {
                    $carry[] = substr(strstr($exclusion, '.'), 1);
                } else {
                    $carry[] = $exclusion;
                }

                return $carry;
            }, []);
    }

    /**
     * Get fallback eager loading configuration when provider is not available.
     *
     * @param  string  $resource_class
     * @return array
     */
    protected function getFallbackEagerLoadConfig(string $resource_class): array
    {
        // Fallback configuration focused on settings eager loading
        $config = [
            // ApplicationResource - Essential relations only
            '\\Verifast\\Applications\\Http\\Resources\\ApplicationResource' => [
                // Core relations that ApplicationResource actually uses
                'workflow',
                'organization',
                'flags',
                'portfolio',
                // Settings eager loading - THE MAIN FOCUS
                [
                    'relation' => 'settings',
                    'type' => 'all',
                    'with' => ['values', 'option'],
                ],
            ],
            
            // WorkflowResource - Minimal config for settings
            '\\Verifast\\Applications\\Http\\Resources\\Workflows\\WorkflowResource' => [
                // Only essential relations that WorkflowResource uses
                'organization',
                'restrictions',
                'steps',
                'paths',
                // Settings eager loading
                [
                    'relation' => 'settings',
                    'type' => 'all',
                    'with' => ['values', 'option'],
                ],
            ],
        ];

        return $config[$resource_class] ?? [];
    }
}
