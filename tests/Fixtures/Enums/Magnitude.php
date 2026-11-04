<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

/**
 * Fixture int-backed enum spanning zero, a negative value and the bounds of the
 * integer range.
 *
 * Proves a backed enum value is coerced as Laravel's enum rule coerces it,
 * including at the edges of the integer range.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum Magnitude: int
{
    case ZERO     = 0;
    case ONE      = 1;
    case NEGATIVE = -3;
    case MAXIMUM  = PHP_INT_MAX;
    case MINIMUM  = PHP_INT_MIN;
}
