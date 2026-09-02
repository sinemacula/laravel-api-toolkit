<?php

declare(strict_types = 1);

namespace Tests\Fixtures\OpenApi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;

/**
 * Introspection provider that reports no database columns.
 *
 * Decorates a real introspection provider, delegating relation resolution and
 * every other query, but reporting an empty column set so the exporter runs as
 * though no live column introspection were available. Field types must then
 * degrade to the declared model cast alone, exercising the database-optional
 * emission path end to end.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ColumnlessIntrospectionProvider implements SchemaIntrospectionProvider
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $inner
     */
    public function __construct(

        /** The real provider delegated to for everything but columns. */
        private SchemaIntrospectionProvider $inner,
    ) {}

    /**
     * Get the database columns for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    #[\Override]
    public function getColumns(Model $model): array
    {
        return [];
    }

    /**
     * Get the per-column type and nullability definitions for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>
     */
    #[\Override]
    public function getColumnDefinitions(Model $model): array
    {
        return [];
    }

    /**
     * Get the indexes declared on the given model's table.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null
     */
    #[\Override]
    public function getIndexes(Model $model): ?array
    {
        return $this->inner->getIndexes($model);
    }

    /**
     * Determine whether the given key is an Eloquent relation on the model.
     *
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    #[\Override]
    public function isRelation(string $key, Model $model): bool
    {
        return $this->inner->isRelation($key, $model);
    }

    /**
     * Resolve the relation instance for the given key on the model.
     *
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>|null
     */
    #[\Override]
    public function resolveRelation(string $key, Model $model): ?Relation
    {
        return $this->inner->resolveRelation($key, $model);
    }

    /**
     * Get the soft-delete column for the model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     */
    #[\Override]
    public function getDeletedAtColumn(Model $model): ?string
    {
        return $this->inner->getDeletedAtColumn($model);
    }

    /**
     * Get the parent-side key columns for the given relation.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
     * @return array<int, string>
     */
    #[\Override]
    public function parentKeysFor(Relation $relation): array
    {
        return $this->inner->parentKeysFor($relation);
    }

    /**
     * Clear all internally cached schema data.
     *
     * @return void
     */
    #[\Override]
    public function flush(): void
    {
        $this->inner->flush();
    }
}
