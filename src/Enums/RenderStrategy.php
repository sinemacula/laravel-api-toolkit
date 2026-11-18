<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * Defines when exceptions render as JSON.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum RenderStrategy: string
{
    /** Every exception renders as JSON */
    case ALWAYS_JSON = 'always_json';

    /** Only a request that expects JSON gets JSON */
    case JSON_WHEN_EXPECTED = 'json_when_expected';

    /** JSON, except a non-JSON request in debug mode */
    case AUTO = 'auto';
}
