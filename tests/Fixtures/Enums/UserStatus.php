<?php

namespace Tests\Fixtures\Enums;

/**
 * Fixture backed enum for user status.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum UserStatus: string
{
    case ACTIVE   = 'active';
    case INACTIVE = 'inactive';
    case BANNED   = 'banned';
}
