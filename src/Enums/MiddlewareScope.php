<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Enums;

/**
 * Defines where a toolkit middleware is registered.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum MiddlewareScope: string
{
    /** The global middleware stack */
    case GLOBAL = 'global';

    /** The api middleware group */
    case API = 'api';
}
