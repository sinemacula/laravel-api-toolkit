<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Examples\Enums;

/**
 * The bodies an example is generated for, each choosing its properties
 * differently.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum ExampleScope
{
    /** A request body, sending every field that can be sent */
    case REQUEST;

    /** A success response body, holding the payload returned by default */
    case RESPONSE;
}
