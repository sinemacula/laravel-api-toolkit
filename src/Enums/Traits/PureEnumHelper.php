<?php

namespace SineMacula\ApiToolkit\Enums\Traits;

/**
 * Pure enum helper trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
trait PureEnumHelper
{
    /**
     * Maps a scalar to an enum instance or null.
     *
     * @param  mixed  $value
     * @return static|null
     */
    public static function tryFrom(mixed $value): ?static
    {
        $value = is_string($value) ? $value : '';

        foreach (self::cases() as $case) {
            if (strcasecmp($case->name, $value) === 0) {
                return $case;
            }
        }

        return null;
    }
}
