<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Enums\TrashedState;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;

/**
 * Applies soft-delete visibility to an Eloquent query builder.
 *
 * Widens the default soft-delete scope to include or isolate trashed records
 * when the request asks for them, but only for models that use SoftDeletes and
 * only when the resolved resource opts in. The gate is closed by default, so an
 * unmapped model or a resource that has not opted in never exposes trashed
 * rows.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SoftDeleteVisibilityApplier
{
    /**
     * Apply the requested soft-delete visibility to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  string|null  $resourceClass
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function apply(Builder $query, ?string $resourceClass, Request $request): Builder
    {
        $state = ApiQuery::getTrashed();

        $applicable = $state !== TrashedState::NONE
            && in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)
            && $this->isGateOpen($resourceClass, $request);

        if (!$applicable) {
            return $query;
        }

        return $state === TrashedState::ONLY
            ? $query->onlyTrashed()  // @phpstan-ignore method.notFound
            : $query->withTrashed(); // @phpstan-ignore method.notFound
    }

    /**
     * Determine whether the resolved resource opts in to trashed visibility for
     * the current request.
     *
     * @param  string|null  $resourceClass
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    private function isGateOpen(?string $resourceClass, Request $request): bool
    {
        if ($resourceClass === null || !is_subclass_of($resourceClass, ApiResource::class)) {
            return false;
        }

        return $resourceClass::allowsTrashed($request) === true;
    }
}
