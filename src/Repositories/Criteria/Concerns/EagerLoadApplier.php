<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;

/**
 * Applies eager loading to an Eloquent query builder.
 *
 * Resolves and applies eager loads and eager load counts based on
 * the resource schema and requested fields, using the metadata
 * provider and the parsed API query state.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class EagerLoadApplier
{
    /**
     * Apply eager loading to the query.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  \SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider  $metadataProvider
     * @param  string|null  $resourceClass
     * @param  string|null  $resourceType
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function apply(
        Builder $query,
        ResourceMetadataProvider $metadataProvider,
        ?string $resourceClass,
        ?string $resourceType,
    ): Builder {
        if ($resourceClass === null || !is_subclass_of($resourceClass, ApiResource::class)) {
            return $query;
        }

        $fields = in_array(':all', ApiQuery::getFields($resourceType) ?? [], true)
            ? $metadataProvider->getAllFields($resourceClass)
            : $metadataProvider->resolveFields($resourceClass);

        if (empty($fields)) {
            return $query;
        }

        $with = $metadataProvider->eagerLoadMapFor($resourceClass, $fields);

        if (!empty($with)) {
            $query->with($with);
        }

        $requestedCounts = ApiQuery::getCounts($resourceType) ?? [];
        $withCounts      = $metadataProvider->eagerLoadCountsFor($resourceClass, $requestedCounts);

        if (!empty($withCounts)) {
            $query->withCount($withCounts);
        }

        return $query;
    }
}
