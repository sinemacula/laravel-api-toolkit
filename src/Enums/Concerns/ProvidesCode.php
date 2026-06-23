<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums\Concerns;

/**
 * Provides code trait.
 *
 * This is a helper trait to return codes from integer backed enums.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
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
