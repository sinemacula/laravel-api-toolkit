<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

/**
 * Fixture pure enum whose case names collide once lowercased.
 *
 * Proves an enum with no unambiguous lowercase wire form fails loud.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum CollidingPlatform
{
    // phpcs:disable SineMacula.NamingConventions.ValidEnumCaseName.NotUpperSnakeCase
    case Web;

    // phpcs:enable SineMacula.NamingConventions.ValidEnumCaseName.NotUpperSnakeCase
    case WEB;
}
