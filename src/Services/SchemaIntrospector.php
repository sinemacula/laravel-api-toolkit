<?php

namespace SineMacula\ApiToolkit\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Schema introspector.
 *
 * Provides column listing, searchable column resolution, relation
 * detection, and relation type reporting for Eloquent models.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class SchemaIntrospector implements SchemaIntrospectionProvider
{
    /** @var array<string, array<int, string>> */
    private array $columns = [];

    /** @var array<string, array<int, string>> */
    private array $searchable = [];

    /**
     * Get the database columns for the given model.
     *
     * Results are cached for the duration of the request.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    #[\Override]
    public function getColumns(Model $model): array
    {
        if (isset($this->columns[$model::class])) {
            return $this->columns[$model::class];
        }

        $cacheKey = CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey([$model::class]);

        /** @var array<int, string> $cached */
        $cached = Cache::memo()->get($cacheKey, []);

        if (!empty($cached)) {
            $this->columns[$model::class] = $cached;

            return $cached;
        }

        $columns = Schema::getColumnListing($model->getTable());

        Cache::memo()->rememberForever($cacheKey, fn () => $columns);

        $this->columns[$model::class] = $columns;

        return $columns;
    }

    /**
     * Get the searchable columns for the given model, with configured
     * exclusions applied.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    #[\Override]
    public function getSearchableColumns(Model $model): array
    {
        if (isset($this->searchable[$model::class])) {
            return $this->searchable[$model::class];
        }

        $table      = $model->getTable();
        $exclusions = [];

        /** @var array<int, string> $configExclusions */
        $configExclusions = Config::get('api-toolkit.repositories.searchable_exclusions', []);

        foreach ($configExclusions as $exclusion) {
            if (str_contains($exclusion, '.') && strtok($exclusion, '.') === $table) {
                $exclusions[] = substr(strstr($exclusion, '.'), 1);
            } else {
                $exclusions[] = $exclusion;
            }
        }

        $searchable = array_diff($this->getColumns($model), $exclusions);

        $this->searchable[$model::class] = $searchable;

        return $searchable;
    }

    /**
     * Determine whether the given column is searchable for the model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @return bool
     */
    #[\Override]
    public function isSearchable(Model $model, string $column): bool
    {
        return in_array($column, $this->getSearchableColumns($model), true);
    }

    /**
     * Determine whether the given key is an Eloquent relation on the
     * model.
     *
     * Results are cached for the duration of the request.
     *
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    #[\Override]
    public function isRelation(string $key, Model $model): bool
    {
        return Cache::memo()->rememberForever(CacheKeys::MODEL_RELATIONS->resolveKey([
            $model::class,
            $key,
        ]), function () use ($key, $model) {
            if (!method_exists($model, $key) || !is_callable([$model, $key])) {
                return false;
            }

            try {
                return $model->{$key}() instanceof Relation; // @phpstan-ignore method.dynamicName
            } catch (\LogicException|\ReflectionException $e) {
                Log::error("Failed to detect relation '{$key}' on " . $model::class . ": {$e->getMessage()}");

                return false;
            }
        });
    }

    /**
     * Resolve the relation instance for the given key on the model,
     * or return null if the key is not a relation.
     *
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>|null
     */
    #[\Override]
    public function resolveRelation(string $key, Model $model): ?Relation
    {
        if (!$this->isRelation($key, $model)) {
            return null;
        }

        return $model->{$key}(); // @phpstan-ignore method.dynamicName
    }
}
