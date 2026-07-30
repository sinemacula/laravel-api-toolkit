<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums\Alternate;

use SineMacula\ApiToolkit\OpenApi\Attributes\SchemaName;

/**
 * Fixture enum sharing the basename Tier under a #[SchemaName] override.
 *
 * Its override name resolves the collision with the other Tier enum, proving
 * the attribute disambiguates two basename-colliding enums.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[SchemaName('AlternateTier')]
enum Tier: string
{
    case STANDARD = 'standard';
    case PREMIUM  = 'premium';
}
