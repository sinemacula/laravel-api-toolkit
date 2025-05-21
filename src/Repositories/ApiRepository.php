<?php

namespace SineMacula\ApiToolkit\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use ReflectionException;
use ReflectionMethod;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\Repositories\Repository;

/**
 * The base API repository.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
abstract class ApiRepository extends Repository
{
    /** @var array<int, string> */
    protected array $casts = [];

    /** @var array<string, array> */
    protected array $buffer = [];

    /**
     * Apply the API criteria to the next request.
     *
     * @return static
     */
    public function withApiCriteria(): static
    {
        $this->withCriteria($this->app->make(ApiCriteria::class));

        return $this;
    }

    /**
     * Return a paginated collection.
     *
     * @return mixed
     */
    public function paginate(): mixed
    {
        $this->applyCriteria();
        $this->applyScopes();

        $method = $this->resolvePaginationMethod();

        if ($method === 'cursorPaginate') {
            $results = $this->model->cursorPaginate(ApiQuery::getLimit(), '*', 'cursor', ApiQuery::getCursor());
        } else {
            $results = $this->model->paginate(ApiQuery::getLimit(), '*', 'page', ApiQuery::getPage());
        }

        $results->appends(Request::query());

        return $this->resetAndReturn($results);
    }

    /**
     * Set the attributes for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Support\Collection|array  $attributes
     * @return bool
     */
    public function setAttributes(Model $model, Collection|array $attributes): bool
    {
        $attributes = $attributes instanceof Collection ? $attributes->all() : $attributes;

        $sync_attributes = [];

        foreach ($attributes as $attribute => $value) {

            $cast = $this->casts[$attribute] ?? $this->resolveCastForAttribute($attribute);

            if ($cast) {

                $this->casts[$attribute] = $cast;

                if ($cast === 'sync') {
                    $sync_attributes[$attribute] = $value;
                } else {
                    $this->setAttribute($model, $attribute, $value, $cast);
                }
            }
        }

        $saved = $model->save();

        // We handle sync relations after other attributes because we need to
        // ensure the model has been saved before we sync, and we also need to
        // make sure all given attributes have been saved to ensure no required
        // columns are missing
        foreach ($sync_attributes as $attribute => $value) {
            $this->setAttribute($model, $attribute, $value, 'sync');
        }

        $this->storeCastsInCache();

        return $saved;
    }

    /**
     * Flush the model buffer.
     *
     * @return void
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $inserts = [];
        $updates = [];

        foreach ($this->buffer as ['model' => $model]) {
            if (!$model->exists) {
                $inserts[] = $model;
            } elseif ($model->isDirty()) {
                $updates[] = $model;
            }
        }

        if (!empty($inserts)) {
            $this->bulkInsert($inserts);
        }

        if (!empty($updates)) {
            $this->bulkUpdate($inserts);
        }

        foreach ($this->buffer as ['model' => $model, 'sync' => $sync]) {
            foreach ($sync as $relation => $value) {
                $this->setAttribute($model, $relation, $value, 'sync');
            }
        }

        $this->clear();
    }

    /**
     * Clear the model buffer.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->buffer = [];
    }

    /**
     * Scopes the model by the given id.
     *
     * @param  int|string|null  $id
     * @param  string  $column
     * @return static
     */
    public function scopeById(int|string|null $id, string $column = 'id'): static
    {
        return $this->scopeByIds([$id], $column);
    }

    /**
     * Scopes the model by the given ids.
     *
     * @param  array  $ids
     * @param  string  $column
     * @return static
     */
    public function scopeByIds(array $ids, string $column = 'id'): static
    {
        return $this->addScope(function (Builder $query) use ($column, $ids) {
            $query->whereIn($column, array_unique($ids));
        });
    }

    /**
     * Boot the repository instance.
     *
     * This is a useful method for setting immediate properties when extending
     * the base repository class.
     *
     * @return void
     */
    protected function boot(): void
    {
        $this->resolveAttributeCasts();
    }

    /**
     * Add a model to the buffer.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array  $sync
     * @return void
     */
    private function buffer(Model $model, array $sync = []): void
    {
        $id = spl_object_id($model);

        if (!isset($this->buffer[$id])) {
            $this->buffer[$id] = [
                'model' => $model,
                'sync'  => $sync
            ];
        } else {
            $this->buffer[$id]['sync'] = array_merge(
                $this->buffer[$id]['sync'] ?? [],
                $sync
            );
        }
    }

    /**
     * Apply a bulk insert.
     *
     * @param  array  $data
     * @return void
     */
    private function bulkInsert(array $data): void
    {
        //
    }

    /**
     * Apply a bulk update.
     *
     * @param  array  $data
     * @return void
     */
    private function bulkUpdate(array $data): void
    {
        //
    }

    /**
     * Resolve which pagination method to use.
     *
     * @return string
     */
    private function resolvePaginationMethod(): string
    {
        if (Request::query('pagination') === 'cursor' || Request::has('cursor')) {
            return 'cursorPaginate';
        }

        return 'paginate';
    }

    /**
     * Resolve the cast type for the given attribute.
     *
     * The purpose of this method is to obtain either the native cast type from
     * the Laravel model cast, or to obtain the type of relation for the given
     * attribute. This ensures that each value can be saved safely on the model.
     *
     * @param  string  $attribute
     * @param  string|null  $cast
     * @return string|null
     */
    private function resolveCastForAttribute(string $attribute, ?string $cast = null): ?string
    {
        $cast ??= $this->model->getCasts()[$attribute] ?? null;

        if (!$cast) {
            return $this->resolveCastForRelation($attribute);
        }

        $map = Config::get('api-toolkit.repositories.cast_map');

        foreach ($map as $native_cast => $laravel_casts) {
            foreach ($laravel_casts as $laravel_cast) {
                if ($this->castMatchesLaravelCast($cast, $laravel_cast)) {
                    return $native_cast;
                }
            }
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
            'enum'      => $this->setEnumAttribute($model, $attribute, $value),
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
        Cache::rememberForever(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([$this->model()]), fn () => $this->casts);
    }

    /**
     * Attempt to resolve the repository cast based on the relation type of the
     * given attribute.
     *
     * @param  string  $attribute
     * @return string|null
     */
    private function resolveCastForRelation(string $attribute): ?string
    {
        try {

            $method = new ReflectionMethod($this->model(), $attribute);

            if ($method->getNumberOfParameters() === 0 && !$method->isStatic()) {

                $relation = $method->invoke($this->model);

                return match (true) {
                    $relation instanceof BelongsTo     => 'associate',
                    $relation instanceof MorphTo       => 'associate',
                    $relation instanceof BelongsToMany => 'sync',
                    $relation instanceof MorphToMany   => 'sync',
                    default                            => null
                };
            }

        } catch (ReflectionException $exception) {
            Log::error("Failed to resolve relation for attribute {$attribute}: {$exception->getMessage()}");
        }

        return null;
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

        if (str_contains($laravel_cast, '*')) {
            $pattern = '/^' . str_replace('*', '.*', $laravel_cast) . '$/';

            return preg_match($pattern, $cast);
        }

        return $cast === $laravel_cast;
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
        $model->{$attribute} = !is_null($value) ? (int) $value : null;
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
        $model->{$attribute} = (bool) $value;
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
        $model->{$attribute} = !is_null($value) ? $value : null;
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
        $model->{$attribute} = $value ? (object) $value : null;
    }

    /**
     * Set the attribute value for an enum.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $attribute
     * @param  mixed  $value
     * @return void
     */
    private function setEnumAttribute(Model $model, string $attribute, mixed $value): void
    {
        $model->{$attribute} = $value;
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
        $model->{Str::camel($attribute)}()->associate($value);
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
        if ($value instanceof Collection || $value instanceof Model) {

            if ($value instanceof Collection) {
                $value = [
                    'values'    => $value,
                    'detaching' => true
                ];
            }

            $values = $value['values']->pluck('id');
        }

        $values ??= $value;
        $detaching = $value['detaching'] ?? true;

        $model->{Str::camel($attribute)}()->sync($values, $detaching);
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
        $model->{$attribute} = $value ? (string) $value : null;
    }

    /**
     * Dynamically resolve the attribute casts for the model.
     *
     * @return void
     */
    private function resolveAttributeCasts(): void
    {
        if ($this->casts = $this->resolveCastsFromCache()) {
            return;
        }

        foreach ($this->model->getCasts() as $attribute => $cast) {
            $this->casts[$attribute] = $this->resolveCastForAttribute($attribute, $cast);
        }

        $this->storeCastsInCache();
    }

    /**
     * Attempt to resolve the casts from the cache.
     *
     * @return array
     */
    private function resolveCastsFromCache(): array
    {
        return Cache::get(CacheKeys::REPOSITORY_MODEL_CASTS->resolveKey([$this->model()]), []);
    }
}
