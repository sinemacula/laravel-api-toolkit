<?php

namespace SineMacula\ApiToolkit\Contracts;

/**
 * Error code enumeration interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
interface ErrorCodeInterface
{
    /**
     * Get the error code.
     *
     * @return int
     */
    public function getCode(): int;
}
