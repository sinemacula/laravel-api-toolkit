<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Contracts;

/**
 * Error code enumeration interface.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
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
