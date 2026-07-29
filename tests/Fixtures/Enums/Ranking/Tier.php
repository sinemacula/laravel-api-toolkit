<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums\Ranking;

/**
 * Fixture enum sharing the basename Tier under no override.
 *
 * Pairs with the other suffix-free Tier enum to prove two basename-colliding
 * enums fail loud when neither disambiguates with #[SchemaName].
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum Tier: string
{
    case ENTRY = 'entry';
    case ELITE = 'elite';
}
