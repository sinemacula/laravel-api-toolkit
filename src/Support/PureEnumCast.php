<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Support;

use Illuminate\Contracts\Database\Eloquent\Castable;

/**
 * Recognises a model cast that stores a pure (unbacked) enum.
 *
 * Mirrors Eloquent: an enum that is also castable is cast by its caster.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class PureEnumCast
{
    /**
     * Return the pure enum the given model cast stores, or null when the cast
     * names anything else, a backed enum included.
     *
     * @param  mixed  $cast
     * @return class-string<\UnitEnum>|null
     */
    public static function enumOf(mixed $cast): ?string
    {
        if (!is_string($cast) || !enum_exists($cast) || is_subclass_of($cast, \BackedEnum::class) || is_subclass_of($cast, Castable::class)) {
            return null;
        }

        return $cast;
    }
}
