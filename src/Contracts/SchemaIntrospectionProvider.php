<?php

namespace SineMacula\ApiToolkit\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Schema introspection provider interface.
 *
 * Defines the public API for all schema introspection operations,
 * including column listing, searchable column resolution, relation
 * detection, and relation type reporting.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface SchemaIntrospectionProvider
{
    /**
     * Get the database columns for the given model.
     *
     * Results are cached for the duration of the request.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    public function getColumns(Model $model): array;

    /**
     * Get the searchable columns for the given model, with configured
     * exclusions applied.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    public function getSearchableColumns(Model $model): array;

    /**
     * Determine whether the given column is searchable for the model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $column
     * @return bool
     */
    public function isSearchable(Model $model, string $column): bool;

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
    public function isRelation(string $key, Model $model): bool;

    /**
     * Resolve the relation instance for the given key on the model,
     * or return null if the key is not a relation.
     *
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>|null
     */
    public function resolveRelation(string $key, Model $model): ?Relation;

    /**
     * Clear all internally cached schema data.
     *
     * @return void
     */
    public function flush(): void;
}
