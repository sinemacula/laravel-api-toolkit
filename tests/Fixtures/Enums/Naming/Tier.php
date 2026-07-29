<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums\Naming;

/**
 * Fixture enum sharing the basename Tier across namespaces.
 *
 * Pairs with the alternate Tier enum to exercise the enum name collision guard.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum Tier: string
{
    case FREE = 'free';
    case PAID = 'paid';
}
