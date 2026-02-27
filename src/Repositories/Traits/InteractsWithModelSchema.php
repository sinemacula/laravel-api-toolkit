<?php

namespace SineMacula\ApiToolkit\Repositories\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Provides utilities to interact with the database schema of Eloquent models.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait InteractsWithModelSchema
{
    /** @var array<string, list<string>> */
    private array $columns = [];

    /**
     * Get the database columns associated with the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return list<string>
     */
    private function getColumnsFromModel(Model $model): array
    {
        return $this->columns[$model::class] ??= $this->resolveColumnsFromModel($model);
    }

    /**
     * Resolve the columns associated with the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return list<string>
     */
    private function resolveColumnsFromModel(Model $model): array
    {
        if ($columns = $this->resolveColumnsFromCacheForModel($model)) {
            return $columns;
        }

        $columns = Schema::getColumnListing($model->getTable());

        $this->storeColumnsInCacheForModel($columns, $model);

        return $columns;
    }

    /**
     * Resolve the columns from the cache.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return list<string>
     */
    private function resolveColumnsFromCacheForModel(Model $model): array
    {
        return Cache::memo()->get(CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey([$model::class]), []);
    }

    /**
     * Store the columns in the cache for the given model.
     *
     * @param  list<string>  $columns
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    private function storeColumnsInCacheForModel(array $columns, Model $model): void
    {
        Cache::memo()->rememberForever(CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey([$model::class]), fn () => $columns);
    }
}
