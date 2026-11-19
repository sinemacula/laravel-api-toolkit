<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;

/**
 * Applies eager loading to an Eloquent query builder.
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
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate  $trashedGate
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function apply(Builder $query, ResourceMetadataProvider $metadataProvider, ?string $resourceClass, ?string $resourceType, RelationTrashedGate $trashedGate): Builder
    {
        if ($resourceClass === null || $resourceType === null || !is_subclass_of($resourceClass, ApiResourceInterface::class)) {
            return $query;
        }

        $fields = [...FieldResolver::effectiveFields($resourceClass, null, false), ...$metadataProvider->getFixedFields($resourceClass)];

        if (empty($fields)) {
            return $query;
        }

        $with = array_filter(
            $trashedGate->decorate($metadataProvider->eagerLoadMapFor($resourceClass, $fields), $query->getModel(), ApiQuery::getTrashed()),
            static fn (mixed $load): bool => is_string($load) || is_array($load) || $load instanceof \Closure,
        );

        if (!empty($with)) {
            $query->with($with);
        }

        if (in_array('aggregates', $fields, true)) {

            $aggregates = $metadataProvider->eagerLoadAggregatesFor($resourceClass, ApiQuery::getAggregates($resourceType) ?? []);

            foreach ($trashedGate->decorateAggregates($aggregates, ApiQuery::getTrashed()) as $entry) {
                $query->withAggregate($entry['relation'], $entry['column'], $entry['function']);
            }
        }

        return $query;
    }
}
