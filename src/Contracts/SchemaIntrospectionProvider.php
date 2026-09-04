<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Schema introspection provider interface.
 *
 * Defines the public API for all schema introspection operations, including
 * column listing, column definition resolution, relation detection, and
 * relation type reporting.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface SchemaIntrospectionProvider
{
    /**
     * Get the database columns for the given model.
     *
     * The listing is read from the connection the model itself resolves, and
     * results are cached for the duration of the request.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    public function getColumns(Model $model): array;

    /**
     * Get the per-column type and nullability definitions for the given model,
     * keyed by column name.
     *
     * Results are cached forever per model, mirroring getColumns().
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>
     */
    public function getColumnDefinitions(Model $model): array;

    /**
     * Get the indexes declared on the given model's table, or null when the
     * catalogue could not be read.
     *
     * The two answers are distinct and callers must keep them apart. Null says
     * the catalogue is unverifiable - the connection could not be reached, or
     * the table is not there to describe - so nothing has been proved either
     * way; an empty array says the catalogue was read and the table genuinely
     * carries no index, which is a real answer a declaration can fail against.
     * Reading the first as the second turns every boot without a database, or
     * before the migrations have run, into a hard failure.
     *
     * Results are cached forever per model, mirroring getColumns(). The read
     * belongs to boot-time validation and never to the request path.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null
     */
    public function getIndexes(Model $model): ?array;

    /**
     * Determine whether the given key is an Eloquent relation on the model.
     *
     * Results are cached for the duration of the request.
     *
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    public function isRelation(string $key, Model $model): bool;

    /**
     * Resolve the relation instance for the given key on the model, or return
     * null if the key is not a relation.
     *
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>|null
     */
    public function resolveRelation(string $key, Model $model): ?Relation;

    /**
     * Get the soft-delete column for the model, or null when it does not use
     * SoftDeletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     */
    public function getDeletedAtColumn(Model $model): ?string;

    /**
     * Get the parent-side key columns for the given relation, including morph
     * type/id columns.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
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
