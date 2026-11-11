<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Enums;

/**
 * Where a service was invoked from.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum ServiceSource
{
    /** Invoked via an HTTP request */
    case HTTP;

    /** Invoked from a queued job */
    case QUEUE;

    /** Invoked from an Artisan console command */
    case CONSOLE;

    /** Invoked programmatically from within the application */
    case INTERNAL;
}
