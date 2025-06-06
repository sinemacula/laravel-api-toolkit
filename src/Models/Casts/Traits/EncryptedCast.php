<?php

namespace SineMacula\ApiToolkit\Models\Casts\Traits;

use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypted cast trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
trait EncryptedCast
{
    /**
     * Cast the given value.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     * @return mixed
     */
    abstract public function get(Model $model, string $key, mixed $value, array $attributes): mixed;

    /**
     * Prepare the given value for storage.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     * @return array|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (!is_null($value)) {

            Cache::forget($this->getCacheKey($model, $key));

            return [$key => Crypt::encryptString(Json::encode($value))];
        }

        return null;
    }

    /**
     * Generate a unique cache key for the model attribute.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @return string
     */
    protected function getCacheKey(Model $model, string $key): string
    {
        return implode(':', ['encrypted', $model->getTable(), $model->getKey(), $key]);
    }

    /**
     * Decrypt a string value with caching to avoid repeated decryption.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  string  $value
     * @return mixed
     */
    protected function decrypt(Model $model, string $key, string $value): mixed
    {
        return Cache::rememberForever($this->getCacheKey($model, $key), function () use ($value) {
            return json_decode(Crypt::decryptString($value), false);
        });
    }

    /**
     * Get the serialized representation of the value.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     * @return array|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return !is_null($value) ? $value->toArray() : null;
    }
}
