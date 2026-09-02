<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Schema introspector.
 *
 * Provides column listing, column definition resolution, relation detection,
 * and relation type reporting for Eloquent models.
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

        if (Cache::memo()->has($cacheKey)) {

            /** @var array<int, string> $cached */
            $cached = Cache::memo()->get($cacheKey, []);

            $this->columns[$model::class] = $cached;

            return $cached;
        }

        try {
            $columns = Schema::getColumnListing($model->getTable());

            $this->metadataCacheWriter->rememberMetadataForever($cacheKey, fn () => $columns);
        } catch (\Throwable) {

            // No live connection: degrade to an empty listing, uncached.
            $columns = [];
        }

        return $this->columns[$model::class] = $columns;
    }

    /**
     * Get the per-column type and nullability definitions for the given model,
     * keyed by column name.
     *
     * Results are cached forever per model, mirroring getColumns().
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>
     */
    #[\Override]
    public function getColumnDefinitions(Model $model): array
    {
        if (isset($this->columnDefinitions[$model::class])) {
            return $this->columnDefinitions[$model::class];
        }

        $cacheKey = CacheKeys::MODEL_SCHEMA_COLUMN_DEFINITIONS->resolveKey([$model::class]);

        /** @var array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition> $cached */
        $cached = Cache::memo()->get($cacheKey, []);

        if (!empty($cached)) {
            $this->columnDefinitions[$model::class] = $cached;

            return $cached;
        }

        try {
            $definitions = $this->mapColumnDefinitions(Schema::getColumns($model->getTable()));

            $this->metadataCacheWriter->rememberMetadataForever($cacheKey, fn () => $definitions);
        } catch (\Throwable) {

            // No live connection: degrade to an empty set, uncached.
            $definitions = [];
        }

        return $this->columnDefinitions[$model::class] = $definitions;
    }

    /**
     * Get the indexes declared on the given model's table, or null when the
     * connection could not be inspected.
     *
     * A connection that could not be read reports null, so a boot with no
     * database behind it proves nothing rather than proving the table has no
     * index. A connection that was read and carries nothing reports an empty
     * list, which is a real answer. The failure is not cached, so a later run
     * against a live connection resolves the catalogue.
     *
     * Results are cached forever per model, mirroring getColumns().
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null
     */
    #[\Override]
    public function getIndexes(Model $model): ?array
    {
        return array_key_exists($model::class, $this->indexes)
            ? $this->indexes[$model::class]
            : $this->resolveIndexes($model);
    }

    /**
     * Determine whether the given key is an Eloquent relation on the model.
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
     * Resolve the relation instance for the given key on the model, or return
     * null if the key is not a relation.
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
     * Get the soft-delete column for the model, or null when it does not use
     * SoftDeletes.
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
     * Get the parent-side key columns for the given relation, including morph
     * type/id columns.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>  $relation
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
     * Map the raw Schema::getColumns() rows into column definitions keyed by
     * column name.
     *
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>
     */
    private function mapColumnDefinitions(array $columns): array
    {
        $definitions = [];

        foreach ($columns as $column) {

            /** @var string $name */
            $name = $column['name'];

            /** @var string $typeName */
            $typeName = $column['type_name'];

            $definitions[$name] = new ColumnDefinition(
                name    : $name,
                typeName: strtolower($typeName),
                nullable: (bool) $column['nullable'],
            );
        }

        return $definitions;
    }

    /**
     * Read the catalogue behind the model's table, serving the persistent cache
     * where it is warm and reporting null where the connection could not be
     * inspected.
     *
     * The answer is held on the instance whichever way it resolved. The failure
     * is not written to the persistent cache, so a later run against a live
     * connection resolves the catalogue rather than serving the silence back.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null
     */
    private function resolveIndexes(Model $model): ?array
    {
        $cacheKey = CacheKeys::MODEL_SCHEMA_INDEXES->resolveKey([$model::class]);

        if (Cache::memo()->has($cacheKey)) {

            /** @var array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition> $cached */
            $cached = Cache::memo()->get($cacheKey, []);

            return $this->indexes[$model::class] = $cached;
        }

        try {
            $indexes = $this->mapIndexDefinitions(Schema::getIndexes($model->getTable()));
        } catch (\Throwable) {

            // No live connection: the catalogue is unverifiable, not empty.
            return $this->indexes[$model::class] = null;
        }

        $this->metadataCacheWriter->rememberMetadataForever($cacheKey, fn (): array => $indexes);

        return $this->indexes[$model::class] = $indexes;
    }

    /**
     * Map the raw index catalogue entries into index definitions, passing over
     * any entry the connection reported in a shape that cannot be read.
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
     * Resolve the time-to-live, in seconds, applied to the cached relation
     * lookup, falling back to roughly a day when the config value is unset or
     * non-numeric.
     *
     * Relation detection is schema-static, so a long expiry still caches
     * effectively; the expiry exists to bound the relation cache key space, not
     * to refresh it.
     *
     * @return int
     */
    private function relationCacheTtl(): int
    {
        $ttl = Config::get('api-toolkit.repositories.relation_cache_ttl', 86400);

        return is_numeric($ttl) ? (int) $ttl : 86400;
    }

    /**
     * Determine whether the given reflection method has a return type that is a
     * subclass of Relation.
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
