<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Schema introspection provider interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface SchemaIntrospectionProvider
{
    /**
     * Get the database columns for the given model.
     *
     * Read from the model's own connection and cached in the metadata store.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    public function getColumns(Model $model): array;

    /**
     * Get the column type and nullability definitions for the model, by name.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>
     */
    public function getColumnDefinitions(Model $model): array;

    /**
     * Get the model's table indexes, or null when the catalogue is unreadable.
     *
     * Null proves nothing, while an empty array means the table has no index;
     * treating null as empty would fail every boot without a database.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null
     */
    public function getIndexes(Model $model): ?array;

    /**
     * Determine whether the given key is an Eloquent relation on the model.
     *
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    public function isRelation(string $key, Model $model): bool;

    /**
     * Resolve the relation instance for the key, or null if it is not one.
     *
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>|null
     */
    public function resolveRelation(string $key, Model $model): ?Relation;

    /**
     * Get the model's soft-delete column, or null when it has none.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     */
    public function getDeletedAtColumn(Model $model): ?string;

    /**
     * Get the parent-side key columns for the given relation, including morph
     * type/id columns.
     *
     * @template TRelated of \Illuminate\Database\Eloquent\Model
     * @template TDeclaring of \Illuminate\Database\Eloquent\Model
     * @template TResult
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<TRelated, TDeclaring, TResult>  $relation
     * @return array<int, string>
     */
    public function parentKeysFor(Relation $relation): array;

    /**
     * Clear all internally cached schema data.
     *
     * @return void
     */
    public function flush(): void;
}
