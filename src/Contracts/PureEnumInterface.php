<?php

namespace SineMacula\ApiToolkit\Contracts;

/**
 * Pure enumeration interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
interface PureEnumInterface
{
    /**
     * Maps a scalar to an enum instance or null.
     *
     * @param  mixed  $value
     * @return static|null
     */
    public static function tryFrom(mixed $value): ?static;
}
