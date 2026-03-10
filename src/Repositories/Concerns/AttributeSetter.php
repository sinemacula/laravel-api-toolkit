<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Encapsulates cast resolution and attribute setting for repository
 * models.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AttributeSetter
{
    /** @var array<string, string|null> Resolved cast map keyed by attribute name. */
    private array $casts = [];

    /**
     * Create an attribute setter with the given schema introspector
     * for resolving model relation types.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @return void
     */
    public function __construct(

        /** The schema introspection provider for relation resolution. */
        private readonly SchemaIntrospectionProvider $schemaIntrospector,

    ) {}

    /**
     * Set the attributes on the given model, deferring sync relations
     * until after the model is saved.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<string, mixed>  $attributes
     * @param  string  $modelClass
     * @return bool
     */
    public function setAttributes(Model $model, array $attributes, string $modelClass): bool
    {
        $syncAttributes = [];

        foreach ($attributes as $attribute => $value) {

            $cast = $this->casts[$attribute] ?? $this->resolveCastForAttribute($attribute, null, $model);

            if ($cast) {

                $this->casts[$attribute] = $cast;

                if ($cast === 'sync') {
                    $syncAttributes[$attribute] = $value;
                } else {
                    $this->setAttribute($model, $attribute, $value, $cast);
                }
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
     * Resolve the native cast key for the given attribute by
     * checking the configured cast map, then falling back to
     * relation introspection or enum detection.
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

        /** @var array<string, array<int, string>> $map */
        $map = Config::get('api-toolkit.repositories.cast_map');

        foreach ($map as $nativeCast => $laravelCasts) {

            foreach ($laravelCasts as $laravelCast) {

                if ($this->castMatchesLaravelCast($cast, $laravelCast)) {
                    return $nativeCast;
                }
            }
        }

        return enum_exists($cast) ? 'enum' : 'string';
    }

    /**
     * Map a relation type to its sync/associate cast, or null when
     * the attribute does not correspond to a recognized relation.
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
     * Check whether a model cast matches a configured Laravel cast
     * entry, supporting exact match, class-based match, and
     * wildcard patterns.
     *
     * @param  string  $cast
     * @param  string  $laravelCast
     * @return bool
     */
    private function castMatchesLaravelCast(string $cast, string $laravelCast): bool
    {
        $baseCast = explode(':', $cast)[0];

        if (class_exists($laravelCast) && $baseCast === $laravelCast) {
            return true;
        }

        if (str_contains($laravelCast, '*')) {

            $pattern = '/^' . str_replace('*', '.*', $laravelCast) . '$/';

            return (bool) preg_match($pattern, $cast);
        }

        return $cast === $laravelCast;
    }

    /**
     * Dispatch to the appropriate setter based on the resolved
     * cast type.
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
     * Associate a BelongsTo or MorphTo relation by its foreign
     * key value.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     */
    private function setAssociateAttribute(Model $model, string $attribute, mixed $value): void
    {
        $model->{Str::camel($attribute)}()->associate($value);
    }

    /**
     * Sync a many-to-many relation, accepting an array of IDs, a
     * Collection of models, or a single Model instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     */
    private function setSyncAttribute(Model $model, string $attribute, mixed $value): void
    {
        if ($value instanceof Model || $value instanceof Collection) {

            $value = [
                'values'    => $value instanceof Model ? collect([$value]) : $value,
                'detaching' => true,
            ];

            $values = $value['values']->pluck('id');
        }

        $values ??= $value;
        $detaching = $value['detaching'] ?? true;

        $model->{Str::camel($attribute)}()->sync($values, $detaching);
    }

    /**
     * Persist the resolved casts to the memo cache so subsequent
     * requests skip re-resolution.
     *
     * @param  string  $modelClass
     * @return void
     */
    private function storeCastsInCache(string $modelClass): void
    {
        Cache::memo()->rememberForever(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([$modelClass]), fn () => $this->casts);
    }

    /**
     * Load previously resolved casts from the memo cache, returning
     * an empty array on cache miss.
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
