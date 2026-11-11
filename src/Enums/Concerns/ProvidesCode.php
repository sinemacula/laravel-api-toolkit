<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums\Concerns;

/**
 * Exposes an int-backed enum's value as its code.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait ProvidesCode
{
    /**
     * Return the code of the current enum case.
     *
     * @return int
     */
    public function getCode(): int
    {
        return $this->value;
    }
}
