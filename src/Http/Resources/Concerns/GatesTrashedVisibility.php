<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Http\Request;

/**
 * Gates whether a resource exposes soft-deleted records.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait GatesTrashedVisibility
{
    /**
     * Determine whether the current request may see soft-deleted records.
     *
     * Denies by default, so `?trashed=` is ignored until this is overridden.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     *
     * @SuppressWarnings("php:S1172")
     */
    public static function allowsTrashed(Request $request): bool
    {
        return false;
    }
}
