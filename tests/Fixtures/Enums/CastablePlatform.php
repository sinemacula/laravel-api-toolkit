<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture pure enum that names its own caster.
 *
 * Proves a castable enum is left to its caster rather than treated as a plain
 * enum cast, as Eloquent itself does.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum CastablePlatform implements Castable
{
    case WEB;

    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @param  array<string>  $arguments
     * @return \Illuminate\Contracts\Database\Eloquent\CastsAttributes<mixed, mixed>
     */
    #[\Override]
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes {
            /**
             * Transform the attribute from the underlying model values.
             *
             * @param  \Illuminate\Database\Eloquent\Model  $model
             * @param  string  $key
             * @param  mixed  $value
             * @param  array<string, mixed>  $attributes
             * @return mixed
             */
            #[\Override]
            public function get(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                return $value;
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
        };
    }
}
