<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Support;

use Illuminate\Contracts\Database\Eloquent\Castable;

/**
 * Recognises a model cast that stores an enum, backed or pure.
 *
 * Mirrors Eloquent: an enum that is also castable is cast by its caster.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class EnumCast
{
    /**
     * Return the enum the given model cast stores, or null when the cast names
     * anything else.
     *
     * @param  mixed  $cast
     * @return class-string<\UnitEnum>|null
     */
    public static function enumOf(mixed $cast): ?string
    {
        return is_string($cast) && enum_exists($cast) && !is_subclass_of($cast, Castable::class) ? $cast : null;
    }
}
