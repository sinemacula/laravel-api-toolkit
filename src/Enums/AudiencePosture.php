<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * Defines how a route joins an OpenAPI audience.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum AudiencePosture: string
{
    /** A route joins only by opting in */
    case ALLOWLIST = 'allowlist';

    /** A route joins unless excluded */
    case BLOCKLIST = 'blocklist';
}
