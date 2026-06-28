<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Enums;

/**
 * Service execution status enumeration.
 *
 * Every service execution produces a total result - exactly one of
 * these cases is assigned. There is no pending or indeterminate case
 * because the result only exists once execution has completed. Use
 * exhaustive match expressions to handle both cases without a default
 * arm.
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
