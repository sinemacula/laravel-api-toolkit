<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Support;

use Illuminate\Contracts\Database\Eloquent\Castable;

/**
 * Recognises a model cast that stores a pure (unbacked) enum.
 *
 * Eloquent stores such a cast as the case name exactly as declared, while the
 * API reads and writes it by its wire name, so every path that carries a value
 * between the two asks here whether an attribute's cast needs the translation.
 * The test mirrors Eloquent's own: an enum that is also castable is cast by its
 * caster, not as an enum.
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
