<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Enums;

/**
 * Service execution status enumeration.
 *
 * Replaces the undocumented `?bool` tri-state with self-documenting
 * cases that support exhaustive matching. A result only exists once
 * execution has completed, so there is no pending case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum ServiceStatus
{
    /** The service executed successfully */
    case SUCCEEDED;

    /** The service execution failed */
    case FAILED;
}
