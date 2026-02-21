<?php

namespace SineMacula\ApiToolkit\Repositories\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Manages API repository attribute casting and assignment.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 *
 * @internal
 */
trait ManagesApiRepositoryAttributes
{
    /**
     * Resolve the cast type for the given attribute.
     *
     * @param  string  $attribute
     * @param  string|null  $cast
     * @return string|null
     */
    private function resolveCastForAttribute(string $attribute, ?string $cast = null): ?string
    {
        $cast ??= $this->resolveModelCasts()[$attribute] ?? null;

        if (!$cast) {
            return $this->resolveCastForRelation($attribute);
        }

        $resolved = $this->resolveMappedCast($cast);

        if ($resolved !== null) {
            return $resolved;
        }

        return enum_exists($cast) ? 'enum' : 'string';
    }

    /**
     * Set the given attribute on the model.
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
            'integer'   => $this->setIntegerAttribute($model, $attribute, $value),
            'boolean'   => $this->setBooleanAttribute($model, $attribute, $value),
            'array'     => $this->setArrayAttribute($model, $attribute, $value),
            'object'    => $this->setObjectAttribute($model, $attribute, $value),
            'enum'      => $this->assignAttribute($model, $attribute, $value),
            'associate' => $this->setAssociateAttribute($model, $attribute, $value),
            'sync'      => $this->setSyncAttribute($model, $attribute, $value),
            default     => $this->setStringAttribute($model, $attribute, $value),
        };
    }

    /**
     * Store the casts in the cache.
     *
     * @return void
     */
    private function storeCastsInCache(): void
    {
        Cache::memo()->rememberForever(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([$this->model()]), fn () => $this->casts);
    }

    /**
     * Attempt to resolve the repository cast by relation type.
     *
     * @param  string  $attribute
     * @return string|null
     */
    private function resolveCastForRelation(string $attribute): ?string
    {
        $resolved_cast = null;

        try {
            $method = new \ReflectionMethod($this->model(), $attribute);

            if ($method->getNumberOfParameters() === 0 && !$method->isStatic()) {
                $relation = $method->invoke($this->getModel());

                if ($relation instanceof Relation) {
                    $resolved_cast = match (true) {
                        $relation instanceof BelongsTo     => 'associate',
                        $relation instanceof BelongsToMany => 'sync',
                        default                            => null,
                    };
                }
            }
        } catch (\ReflectionException $exception) {
            Log::error("Failed to resolve relation for attribute {$attribute}: {$exception->getMessage()}");
        }

        return $resolved_cast;
    }

    /**
     * Determine if the given cast matches the given Laravel cast.
     *
     * @param  string  $cast
     * @param  string  $laravel_cast
     * @return bool
     */
    private function castMatchesLaravelCast(string $cast, string $laravel_cast): bool
    {
        $base_cast = explode(':', $cast)[0];

        if (class_exists($laravel_cast) && $base_cast === $laravel_cast) {
            return true;
        }

        if (!str_contains($laravel_cast, '*')) {
            return $cast === $laravel_cast;
        }

        $pattern = '/^' . str_replace('*', '.*', $laravel_cast) . '$/';

        return preg_match($pattern, $cast) === 1;
    }

    /**
     * Set the attribute value for an integer.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     */
    private function setIntegerAttribute(Model $model, string $attribute, mixed $value): void
    {
        if (is_int($value) || is_float($value) || is_bool($value) || is_string($value)) {
            $this->assignAttribute($model, $attribute, (int) $value);
            return;
        }

        if ($value instanceof \Stringable) {
            $this->assignAttribute($model, $attribute, (int) (string) $value);
            return;
        }

        $this->assignAttribute($model, $attribute, null);
    }

    /**
     * Set the attribute value for a boolean.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     */
    private function setBooleanAttribute(Model $model, string $attribute, mixed $value): void
    {
        $this->assignAttribute($model, $attribute, (bool) $value);
    }

    /**
     * Set the attribute value for an array.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     */
    private function setArrayAttribute(Model $model, string $attribute, mixed $value): void
    {
        $this->assignAttribute($model, $attribute, $value);
    }

    /**
     * Set the attribute value for an object.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     */
    private function setObjectAttribute(Model $model, string $attribute, mixed $value): void
    {
        $this->assignAttribute($model, $attribute, $value ? (object) $value : null);
    }

    /**
     * Set the attribute value for an associative relationship.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     */
    private function setAssociateAttribute(Model $model, string $attribute, mixed $value): void
    {
        $relation = $this->resolveRelationInstance($model, $attribute);

        if ($relation instanceof BelongsTo) {
            $relation->associate($value);
        }
    }

    /**
     * Set the attribute value for a synced relationship.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     */
    private function setSyncAttribute(Model $model, string $attribute, mixed $value): void
    {
        $detaching = true;
        $values    = $value;

        if (is_array($value) && array_key_exists('values', $value)) {
            $detaching = $value['detaching'] ?? true;
            $values    = $value['values'];
        }

        $relation = $this->resolveRelationInstance($model, $attribute);

        if ($relation instanceof BelongsToMany) {
            $relation->sync($this->resolveSyncValues($values), $detaching);
        }
    }

    /**
     * Set the attribute value for a string.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     */
    private function setStringAttribute(Model $model, string $attribute, mixed $value): void
    {
        if ($value === null || $value === '' || $value === false) {
            $this->assignAttribute($model, $attribute, null);
            return;
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            $this->assignAttribute($model, $attribute, (string) $value);
            return;
        }

        $this->assignAttribute($model, $attribute, null);
    }

    /**
     * Resolve values provided for a sync-able relationship.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function resolveSyncValues(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return [$value->getKey()];
        }

        if (!$value instanceof Collection) {
            return $value;
        }

        return $value
            ->map(static function (mixed $item): mixed {
                if ($item instanceof Model) {
                    return $item->getKey();
                }

                if (is_array($item) && array_key_exists('id', $item)) {
                    return $item['id'];
                }

                return $item;
            })
            ->all();
    }

    /**
     * Dynamically resolve the attribute casts for the model.
     *
     * @return void
     */
    private function resolveAttributeCasts(): void
    {
        $cached = $this->resolveCastsFromCache();

        if ($cached !== []) {
            $this->casts = $cached;
            return;
        }

        foreach ($this->resolveModelCasts() as $attribute => $cast) {
            $resolved_cast = $this->resolveCastForAttribute($attribute, $cast);

            if ($resolved_cast !== null) {
                $this->casts[$attribute] = $resolved_cast;
            }
        }

        $this->storeCastsInCache();
    }

    /**
     * Attempt to resolve casts from the cache.
     *
     * @return array<string, string>
     */
    private function resolveCastsFromCache(): array
    {
        $casts = Cache::memo()->get(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([$this->model()]), []);

        if (!is_array($casts)) {
            return [];
        }

        $resolved = [];

        foreach ($casts as $attribute => $cast) {
            if (is_string($attribute) && is_string($cast)) {
                $resolved[$attribute] = $cast;
            }
        }

        return $resolved;
    }

    /**
     * Resolve casts from the underlying model.
     *
     * @return array<string, string>
     */
    private function resolveModelCasts(): array
    {
        $casts    = $this->getModel()->getCasts();
        $resolved = [];

        foreach ($casts as $attribute => $cast) {
            if (is_string($attribute) && is_string($cast)) {
                $resolved[$attribute] = $cast;
            }
        }

        return $resolved;
    }

    /**
     * Assign a model attribute safely.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     */
    private function assignAttribute(Model $model, string $attribute, mixed $value): void
    {
        $model->setAttribute($attribute, $value);
    }

    /**
     * Resolve a relation instance for the given attribute.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @return mixed
     */
    private function resolveRelationInstance(Model $model, string $attribute): mixed
    {
        $method = Str::camel($attribute);

        if (!method_exists($model, $method)) {
            return null;
        }

        try {
            return (new \ReflectionMethod($model, $method))->invoke($model);
        } catch (\ReflectionException $exception) {
            Log::error("Failed to resolve relation method {$method}: {$exception->getMessage()}");
            return null;
        }
    }

    /**
     * Resolve cast mapping from configured cast map.
     *
     * @param  string  $cast
     * @return string|null
     */
    private function resolveMappedCast(string $cast): ?string
    {
        $map = Config::get('api-toolkit.repositories.cast_map', []);

        if (!is_array($map)) {
            return null;
        }

        foreach ($map as $native_cast => $laravel_casts) {
            if (!is_string($native_cast) || !is_array($laravel_casts)) {
                continue;
            }

            foreach ($laravel_casts as $laravel_cast) {
                if (is_string($laravel_cast) && $this->castMatchesLaravelCast($cast, $laravel_cast)) {
                    return $native_cast;
                }
            }
        }

        return null;
    }
}
