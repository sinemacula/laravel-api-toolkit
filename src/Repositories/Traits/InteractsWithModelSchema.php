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
 * @copyright   2025 Sine Macula Limited.
 */
trait InteractsWithModelSchema
{
    /** @var array<string, array> */
    private array $columns = [];

    /**
     * Get the database columns associated with the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array
     */
    private function getColumnsFromModel(Model $model): array
    {
        return $this->columns[get_class($model)] ??= $this->resolveColumnsFromModel($model);
    }

    /**
     * Resolve the columns associated with the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array
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
     * @return array
     */
    private function resolveColumnsFromCacheForModel(Model $model): array
    {
        return Cache::get(CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey([get_class($model)]), []);
    }

    /**
     * Store the columns in the cache for the given model.
     *
     * @param  array  $columns
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    private function storeColumnsInCacheForModel(array $columns, Model $model): void
    {
        Cache::rememberForever(CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey([get_class($model)]), fn () => $columns);
    }
}
