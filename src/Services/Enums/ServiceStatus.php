<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Enums;

/**
 * The outcome status of a completed service execution.
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
