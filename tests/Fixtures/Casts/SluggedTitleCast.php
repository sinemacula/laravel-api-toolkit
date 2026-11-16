<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture custom cast that reads a second column beside its own.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @implements \Illuminate\Contracts\Database\Eloquent\CastsAttributes<array<int, mixed>, mixed>
 */
final class SluggedTitleCast implements CastsAttributes
{
    /**
     * Transform the attribute from the underlying model values.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     * @return array<int, mixed>
     */
    #[\Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$value, $attributes['slug'] ?? null];
    }

    /**
     * Transform the attribute to its underlying model values.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     * @return mixed
     */
    #[\Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}
