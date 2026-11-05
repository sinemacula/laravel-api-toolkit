<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

/**
 * Fixture string-backed enum with a value ending in a backslash.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum Escaped: string
{
    case TRAILING = 'dir\\';
    case PLAIN    = 'plain';
}
