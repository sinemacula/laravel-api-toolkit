<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

/**
 * Fixture string-backed enum whose values read as numbers.
 *
 * Proves a non-string value is coerced to a string-backed case as Laravel's
 * enum rule coerces it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum NumericCode: string
{
    case ZERO = '0';
    case ONE  = '1';
    case HUGE = '1.0E+20';
}
