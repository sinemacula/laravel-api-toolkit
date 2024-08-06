<?php

namespace SineMacula\ApiToolkit\Enums\Traits;

/**
 * Provides code trait.
 *
 * This is a helper trait to return codes from integer backed enums.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
trait ProvidesCode
{
    /**
     * Return the current enum case.
     *
     * @return int
     */
    public function getCode(): int
    {
        return $this->value;
    }
}
