<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Casts;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Fixture value object that names its own caster.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class PostalAddress implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @param  array<string>  $arguments
     * @return \Illuminate\Contracts\Database\Eloquent\CastsAttributes<array<int, mixed>, mixed>
     */
    #[\Override]
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new SluggedTitleCast;
    }
}
