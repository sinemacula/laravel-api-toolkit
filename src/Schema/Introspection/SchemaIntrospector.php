<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Schema introspector for Eloquent models.
 *
 * Reads use the model's own connection; cache keys include its schema identity.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SchemaIntrospector implements SchemaIntrospectionProvider
{
    /** @var array<string, array<int, string>> */
    private array $columns = [];

    /** @var array<string, array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>> */
    private array $columnDefinitions = [];

    /** @var array<string, array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null> */
    private array $indexes = [];

    /**
     * Create a new schema introspector.
     *
     * @param  \SineMacula\ApiToolkit\Cache\MetadataCacheWriter  $metadataCacheWriter
     * @return void
     */
    public function __construct(

        /** Writes resolved schema metadata to the persistent cache. */
        private readonly MetadataCacheWriter $metadataCacheWriter,
    ) {}

    /**
     * Get the database columns for the given model.
     *
     * Empty listings are not shared: a table not yet created reads as empty.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, string>
     */
    #[\Override]
    public function getColumns(Model $model): array
    {
        $connection = $this->connectionOf($model);

        if ($connection === null) {
            return [];
        }

        return $this->columns[$this->memoKey($connection, $model)] ??= $this->resolveColumns($model, $connection);
    }

    /**
     * Get the column type and nullability definitions for the model, by name.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>
     */
    #[\Override]
    public function getColumnDefinitions(Model $model): array
    {
        $connection = $this->connectionOf($model);

        if ($connection === null) {
            return [];
        }

        return $this->columnDefinitions[$this->memoKey($connection, $model)] ??= $this->resolveColumnDefinitions($model, $connection);
    }

    /**
     * Get the model's table indexes, or null when the catalogue is unreadable.
     *
     * A missing table reads as an empty catalogue, so it also reports null.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null
     */
    #[\Override]
    public function getIndexes(Model $model): ?array
    {
        $connection = $this->connectionOf($model);

        if ($connection === null) {
            return null;
        }

        $memoKey = $this->memoKey($connection, $model);

        // A memoised null is an answer, which isset() would treat as a miss
        return array_key_exists($memoKey, $this->indexes)
            ? $this->indexes[$memoKey]
            : $this->indexes[$memoKey] = $this->resolveIndexes($model, $connection);
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
        return $this->metadataCacheWriter->rememberMetadata(CacheKeys::MODEL_RELATIONS->resolveKey([
            $model::class,
            $key,
        ]), function () use ($key, $model): bool {
            if (method_exists($model, $key)) {
                return $this->hasRelationReturnType(new \ReflectionMethod($model, $key));
            }

            return $model->relationResolver($model::class, $key) !== null;
        }, $this->relationCacheTtl());
    }

    /**
     * Resolve the relation instance for the key, or null if it is not one.
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

        try {

            return method_exists($model, $key)
                ? $model->{$key}() // @phpstan-ignore method.dynamicName
                : $model->relationResolver($model::class, $key)($model); // @phpstan-ignore callable.nonCallable
        } catch (\LogicException|\ReflectionException $e) {

            Log::warning("Failed to resolve relation '{$key}' on " . $model::class . ": {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Get the soft-delete column, or null when the model does not soft delete.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     */
    #[\Override]
    public function getDeletedAtColumn(Model $model): ?string
    {
        if (!in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return null;
        }

        return $model->getDeletedAtColumn(); // @phpstan-ignore method.notFound, return.type
    }

    /**
     * Get the relation's parent-side key columns, including morph type and id.
     *
     * @template TRelated of \Illuminate\Database\Eloquent\Model
     * @template TDeclaring of \Illuminate\Database\Eloquent\Model
     * @template TResult
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<TRelated, TDeclaring, TResult>  $relation
     * @return array<int, string>
     */
    #[\Override]
    public function parentKeysFor(Relation $relation): array
    {
        $keys = match (true) {
            $relation instanceof MorphTo        => [$relation->getForeignKeyName(), $relation->getMorphType()],
            $relation instanceof BelongsTo      => [$relation->getForeignKeyName()],
            $relation instanceof MorphOneOrMany => [$relation->getLocalKeyName()],
            $relation instanceof HasOneOrMany   => [$relation->getLocalKeyName()],
            default                             => [],
        };

        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
    }

    /**
     * Clear all internally cached schema data.
     *
     * @return void
     */
    #[\Override]
    public function flush(): void
    {
        $this->columns           = [];
        $this->columnDefinitions = [];
        $this->indexes           = [];
    }

    /**
     * Map raw column rows to definitions by name, skipping unreadable rows.
     *
     * @param  array<int, mixed>  $columns
     * @return array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>
     */
    private function mapColumnDefinitions(array $columns): array
    {
        $definitions = [];

        foreach ($columns as $column) {

            $definition = ColumnDefinition::fromCatalogueEntry($column);

            if ($definition === null) {
                continue;
            }

            $definitions[$definition->name] = $definition;
        }

        return $definitions;
    }

    /**
     * Read the model's column listing, sharing only a non-empty one.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, string>
     */
    private function resolveColumns(Model $model, Connection $connection): array
    {
        $cacheKey = CacheKeys::MODEL_SCHEMA_COLUMNS->resolveKey([SchemaIdentity::of($connection), $model::class]);

        /** @var array<int, string> $cached */
        $cached = $this->metadataCacheWriter->readMetadata($cacheKey, []);

        if ($cached !== []) {
            return $cached;
        }

        try {
            $columns = $connection->getSchemaBuilder()->getColumnListing($model->getTable());
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown

            // No live connection: degrade to an empty listing
            $columns = [];
        }

        if ($columns !== []) {
            $this->metadataCacheWriter->storeMetadataForever($cacheKey, $columns);
        }

        return $columns;
    }

    /**
     * Read the model's column definitions, sharing only a non-empty set.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>
     */
    private function resolveColumnDefinitions(Model $model, Connection $connection): array
    {
        $cacheKey = CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey([SchemaIdentity::of($connection), $model::class]);

        /** @var array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition> $cached */
        $cached = $this->metadataCacheWriter->readMetadata($cacheKey, [], StoredSchema::toColumnDefinitions(...));

        if ($cached !== []) {
            return $cached;
        }

        try {
            $definitions = $this->mapColumnDefinitions($connection->getSchemaBuilder()->getColumns($model->getTable()));
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown

            // No live connection: degrade to an empty set
            $definitions = [];
        }

        if ($definitions !== []) {
            $this->metadataCacheWriter->storeMetadataForever($cacheKey, StoredSchema::fromColumnDefinitions($definitions));
        }

        return $definitions;
    }

    /**
     * Read the model's index catalogue, sharing only a readable answer.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null
     */
    private function resolveIndexes(Model $model, Connection $connection): ?array
    {
        $cacheKey = CacheKeys::MODEL_SCHEMA_INDEXES->resolveKey([SchemaIdentity::of($connection), $model::class]);

        /** @var array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null $cached */
        $cached = $this->metadataCacheWriter->readMetadata($cacheKey, null, StoredSchema::toIndexes(...));

        if ($cached !== null) {
            return $cached;
        }

        $indexes = $this->readIndexes($model, $connection);

        if ($indexes !== null) {
            $this->metadataCacheWriter->storeMetadataForever($cacheKey, StoredSchema::fromIndexes($indexes));
        }

        return $indexes;
    }

    /**
     * Read the model's indexes from its connection, or null when unreadable.
     *
     * An empty catalogue with no columns means the table does not exist.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Connection  $connection
     * @return array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null
     */
    private function readIndexes(Model $model, Connection $connection): ?array
    {
        try {
            $indexes = $this->mapIndexDefinitions($connection->getSchemaBuilder()->getIndexes($model->getTable()));
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            return null;
        }

        return $indexes === [] && $this->getColumns($model) === [] ? null : $indexes;
    }

    /**
     * Return the model's connection, or null when it cannot be built.
     *
     * Resolving opens no handle; an unreachable connection fails at the read.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Connection|null
     *
     * @phpstan-ignore return.unusedType
     */
    private function connectionOf(Model $model): ?Connection
    {
        try {
            return $model->getConnection();
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            return null;
        }
    }

    /**
     * Return the key the per-instance caches hold this model's schema under.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string
     */
    private function memoKey(Connection $connection, Model $model): string
    {
        return SchemaIdentity::of($connection) . '|' . $model::class;
    }

    /**
     * Map raw index entries to definitions, skipping unreadable entries.
     *
     * @param  array<int, mixed>  $indexes
     * @return array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>
     */
    private function mapIndexDefinitions(array $indexes): array
    {
        $definitions = [];

        foreach ($indexes as $index) {

            $definition = IndexDefinition::fromCatalogueEntry($index);

            if ($definition === null) {
                continue;
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * Resolve the relation lookup cache TTL in seconds, defaulting to a day.
     *
     * @return int
     */
    private function relationCacheTtl(): int
    {
        $ttl = Config::get('api-toolkit.repositories.relation_cache_ttl', 86400);

        return is_numeric($ttl) ? (int) $ttl : 86400;
    }

    /**
     * Determine whether the method's return type is a Relation subclass.
     *
     * @param  \ReflectionMethod  $method
     * @return bool
     */
    private function hasRelationReturnType(\ReflectionMethod $method): bool
    {
        $returnType = $method->getReturnType();

        if ($returnType instanceof \ReflectionNamedType) {
            return is_subclass_of($returnType->getName(), Relation::class);
        }

        if ($returnType instanceof \ReflectionUnionType) {
            foreach ($returnType->getTypes() as $member) {
                if ($member instanceof \ReflectionNamedType && is_subclass_of($member->getName(), Relation::class)) {
                    return true;
                }
            }
        }

        return false;
    }
}
