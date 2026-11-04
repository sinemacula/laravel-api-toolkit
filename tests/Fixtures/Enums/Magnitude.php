<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

/**
 * Fixture int-backed enum spanning zero and a negative value.
 *
 * Proves an int-backed enum is hydrated only from an integer or the canonical
 * decimal string of one.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum Magnitude: int
{
    case ZERO     = 0;
    case ONE      = 1;
    case NEGATIVE = -3;
}
