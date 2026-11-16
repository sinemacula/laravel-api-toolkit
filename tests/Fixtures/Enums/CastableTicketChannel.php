<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Enums;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Casts\AsStringable;

/**
 * Fixture string-backed enum that names its own caster.
 *
 * Its caster, not its backing value, decides what Eloquent stores.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum CastableTicketChannel: string implements Castable
{
    case EMAIL = 'email';

    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @param  array<string>  $arguments
     * @return \Illuminate\Contracts\Database\Eloquent\CastsAttributes<\Illuminate\Support\Stringable, string|\Stringable>
     */
    #[\Override]
    public static function castUsing(array $arguments): CastsAttributes
    {
        return AsStringable::castUsing($arguments);
    }
}
