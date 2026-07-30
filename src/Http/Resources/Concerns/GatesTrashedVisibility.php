<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Http\Request;

/**
 * Gates whether a resource exposes soft-deleted records.
 *
 * Provides the safe-by-default seam the criteria consults before honouring a
 * `?trashed=with` or `?trashed=only` request. A resource opts in by overriding
 * allowsTrashed() to authorise the caller; until then trashed rows are never
 * exposed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait GatesTrashedVisibility
{
    /**
     * Determine whether the current request may see soft-deleted records.
     *
     * Safe by default: unless a resource overrides this method, a
     * `?trashed=with` or `?trashed=only` request is ignored and only live rows
     * are ever returned. Override to gate the exposure on the caller's
     * authorisation.
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
