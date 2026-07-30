<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

/**
 * Fixture int-backed enum for user level.
 *
 * Proves an int-backed enum documents its component as an integer type.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum UserLevel: int
{
    case BRONZE = 1;
    case SILVER = 2;
    case GOLD   = 3;
}
