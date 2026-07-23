<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Encapsulates cast resolution and attribute setting for repository models.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AttributeSetter
{
    /** @var array<string, string|null> Resolved cast map keyed by attribute name. */
    private array $casts = [];

    /**
     * Create an attribute setter with the given schema introspector for
     * resolving model relation types.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @param  \SineMacula\ApiToolkit\Cache\MetadataCacheWriter  $metadataCacheWriter
     * @return void
     */
    public function __construct(

        /** The schema introspection provider for relation resolution. */
        private readonly SchemaIntrospectionProvider $schemaIntrospector,

        /** Writes resolved cast metadata to the persistent cache. */
        private readonly MetadataCacheWriter $metadataCacheWriter,
    ) {}

    /**
     * Persist the given attributes to the model, deferring sync relations until
     * after the model is saved.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<string, mixed>  $attributes
     * @param  string  $modelClass
     * @return bool
     */
    public function persist(Model $model, array $attributes, string $modelClass): bool
    {
        $syncAttributes = [];

        foreach ($attributes as $attribute => $value) {

            $cast = $this->casts[$attribute] ?? $this->resolveCastForAttribute($attribute, null, $model);

            if (!$cast) {
                continue;
            }

            $this->casts[$attribute] = $cast;

            if ($cast === 'sync') {
                $syncAttributes[$attribute] = $value;
            } else {
                $this->setAttribute($model, $attribute, $value, $cast);
            }
        }

        $saved = $model->save();

        foreach ($syncAttributes as $attribute => $value) {
            $this->setAttribute($model, $attribute, $value, 'sync');
        }

        $this->storeCastsInCache($modelClass);

        return $saved;
    }

    /**
     * Resolve the attribute casts for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $modelClass
     * @return void
     */
    public function resolveAttributeCasts(Model $model, string $modelClass): void
    {
        if ($this->casts = $this->resolveCastsFromCache($modelClass)) {
            return;
        }

        foreach ($model->getCasts() as $attribute => $cast) {
            $this->casts[$attribute] = $this->resolveCastForAttribute($attribute, $cast, $model);
        }

        $this->storeCastsInCache($modelClass);
    }

    /**
     * Resolve the native cast key for the given attribute.
     *
     * An attribute with no cast is treated as a possible relation. A declared
     * object cast is the only cast the setter normalises before assignment;
     * everything else is assigned as-is and left to the model's own cast to
     * convert.
     *
     * @param  string  $attribute
     * @param  string|null  $cast
     * @param  \Illuminate\Database\Eloquent\Model|null  $model
     * @return string|null
     */
    private function resolveCastForAttribute(string $attribute, ?string $cast = null, ?Model $model = null): ?string
    {
        $cast ??= $model?->getCasts()[$attribute] ?? null;

        if (!$cast) {
            return $this->resolveCastForRelation($attribute, $model);
        }

        return $this->isObjectCast($cast) ? 'object' : 'string';
    }

    /**
     * Map a relation type to its sync/associate cast, or null when the
     * attribute does not correspond to a recognized relation.
     *
     * @param  string  $attribute
     * @param  \Illuminate\Database\Eloquent\Model|null  $model
     * @return string|null
     */
    private function resolveCastForRelation(string $attribute, ?Model $model): ?string
    {
        if ($model === null) {
            return null;
        }

        $relation = $this->schemaIntrospector->resolveRelation($attribute, $model);

        if (!$relation) {
            return null;
        }

        return match (true) {
            $relation instanceof MorphTo       => 'associate',
            $relation instanceof BelongsTo     => 'associate',
            $relation instanceof MorphToMany   => 'sync',
            $relation instanceof BelongsToMany => 'sync',
            default                            => null,
        };
    }

    /**
     * Determine whether the given model cast is one of Laravel's object casts.
     *
     * The object cast is the only cast the setter normalises before assignment
     * - a falsy value becomes null and a truthy value is wrapped in stdClass;
     * every other cast is assigned as-is.
     *
     * @param  string  $cast
     * @return bool
     */
    private function isObjectCast(string $cast): bool
    {
        return in_array($cast, ['object', 'encrypted:object'], true);
    }

    /**
     * Dispatch to the appropriate setter based on the resolved cast type.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  string  $cast
     * @return void
     */
    private function setAttribute(Model $model, string $attribute, mixed $value, string $cast): void
    {
        match ($cast) {
            'object'    => $model->setAttribute($attribute, $value ? (object) $value : null),
            'associate' => $this->setAssociateAttribute($model, $attribute, $value),
            'sync'      => $this->setSyncAttribute($model, $attribute, $value),
            default     => $model->setAttribute($attribute, $value),
        };
    }

    /**
     * Associate a BelongsTo or MorphTo relation by its foreign key value.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     *
     * @throws \LogicException
     */
    private function setAssociateAttribute(Model $model, string $attribute, mixed $value): void
    {
        $relation = $this->resolveRelationInstance($model, $attribute);

        if (!($relation instanceof BelongsTo)) {
            throw new \LogicException(sprintf('Attribute "%s" on %s does not resolve to a BelongsTo relation', $attribute, $model::class));
        }

        $relation->associate($value);
    }

    /**
     * Sync a many-to-many relation, accepting an array of IDs, a Collection of
     * models, or a single Model instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     *
     * @throws \LogicException
     */
    private function setSyncAttribute(Model $model, string $attribute, mixed $value): void
    {
        $relation = $this->resolveRelationInstance($model, $attribute);

        if (!($relation instanceof BelongsToMany)) {
            throw new \LogicException(sprintf('Attribute "%s" on %s does not resolve to a BelongsToMany relation', $attribute, $model::class));
        }

        if ($value instanceof Model) {
            $value = collect([$value]);
        }

        if ($value instanceof Collection) {
            $value = $value->pluck($relation->getRelated()->getKeyName());
        }

        $relation->sync($value);
    }

    /**
     * Resolve the relation instance for the given attribute by invoking its
     * camel-cased relation method on the model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @return mixed
     */
    private function resolveRelationInstance(Model $model, string $attribute): mixed
    {
        return \Closure::fromCallable([$model, Str::camel($attribute)])();
    }

    /**
     * Persist the resolved casts to the memo cache so subsequent requests skip
     * re-resolution.
     *
     * @param  string  $modelClass
     * @return void
     */
    private function storeCastsInCache(string $modelClass): void
    {
        $this->metadataCacheWriter()->rememberMetadataForever(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([$modelClass]), fn () => $this->casts);
    }

    /**
     * Get the injected metadata cache writer.
     *
     * @return \SineMacula\ApiToolkit\Cache\MetadataCacheWriter
     */
    private function metadataCacheWriter(): MetadataCacheWriter
    {
        return $this->metadataCacheWriter;
    }

    /**
     * Load previously resolved casts from the memo cache, returning an empty
     * array on cache miss.
     *
     * @param  string  $modelClass
     * @return array<string, string|null>
     */
    private function resolveCastsFromCache(string $modelClass): array
    {
        /** @var array<string, string|null> */
        return Cache::memo()->get(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([$modelClass]), []);
    }
}
