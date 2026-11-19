<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

/**
 * Fixture string-backed enum cast on the role a tag plays on a post.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum TagRole: string
{
    case PRIMARY   = 'primary';
    case SECONDARY = 'secondary';
}
