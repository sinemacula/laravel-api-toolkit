<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

/**
 * Fixture pure enum for the platform a client runs on.
 *
 * Proves a pure enum is read and written by its lowercase case name, including
 * a multi-word case whose underscore survives.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum Platform
{
    case WEB;
    case MOBILE_APP;
    case DESKTOP;
}
