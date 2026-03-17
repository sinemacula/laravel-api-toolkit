<?php

namespace SineMacula\ApiToolkit\Services\Enums;

/**
 * Represents the lifecycle states of a service execution.
 *
 * Replaces the undocumented `?bool` tri-state (`null`/`true`/`false`)
 * with self-documenting cases that support exhaustive matching.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum ServiceStatus
{
    /** The service has not yet been executed */
    case Pending;

    /** The service executed successfully */
    case Succeeded;

    /** The service execution failed */
    case Failed;
}
