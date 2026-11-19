<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Schema\ChildProjection;

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
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationColumnNarrower  $columnNarrower
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RowPerimeter  $rowPerimeter
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function apply(
        Builder $query,
        ResourceMetadataProvider $metadataProvider,
        ?string $resourceClass,
        ?string $resourceType,
        RelationTrashedGate $trashedGate,
        RelationColumnNarrower $columnNarrower,
        RowPerimeter $rowPerimeter,
    ): Builder {
        if ($resourceClass === null || $resourceType === null || !is_subclass_of($resourceClass, ApiResourceInterface::class)) {
            return $query;
        }

        $fields = [...FieldResolver::effectiveFields($resourceClass, null, false), ...$metadataProvider->getFixedFields($resourceClass)];

        if (empty($fields)) {
            return $query;
        }

        $model = $query->getModel();
        $plan  = EagerLoadPlanner::buildProjectionPlan($resourceClass, $fields);
        $paths = ChildProjection::resourcesOf($plan);
        $with  = $columnNarrower->decorate($metadataProvider->eagerLoadMapFor($resourceClass, $fields), $model, $resourceClass, $fields, $plan);

        /** @var array<int|string, array<mixed>|(\Closure(mixed): mixed)|string> $with */
        $with = array_filter(
            $trashedGate->decorate($with, $model, ApiQuery::getTrashed(), $paths),
            static fn (mixed $load): bool => is_string($load) || is_array($load) || $load instanceof \Closure,
        );

        // The model's default loads and any the caller added are read too
        $query->setEagerLoads($rowPerimeter->decorate($query->getEagerLoads(), $model));

        $with = $rowPerimeter->decorate($with, $model, $paths);

        if (!empty($with)) {
            $query->with($with);
        }

        if (in_array('aggregates', $fields, true)) {

            $aggregates = $rowPerimeter->wrapAggregates($metadataProvider->eagerLoadAggregatesFor($resourceClass, ApiQuery::getAggregates($resourceType) ?? []), $resourceClass);

            foreach ($trashedGate->decorateAggregates($aggregates, ApiQuery::getTrashed()) as $entry) {
                $query->withAggregate($entry['relation'], $entry['column'], $entry['function']);
            }
        }

        return $query;
    }
}
