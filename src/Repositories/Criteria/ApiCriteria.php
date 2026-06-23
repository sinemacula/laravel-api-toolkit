<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\LimitApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Repositories\Concerns\ResolvesResource;
use SineMacula\ApiToolkit\Schema\SafetySetDeriver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Repositories\Contracts\CriteriaInterface;

/**
 * API criteria.
 *
 * Thin orchestrator that delegates filtering, eager loading, limiting, and
 * ordering to single-responsibility concern classes.
 *
 * @implements \SineMacula\Repositories\Contracts\CriteriaInterface<\Illuminate\Database\Eloquent\Model>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ApiCriteria implements CriteriaInterface
{
    use ResolvesResource;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier */
    private readonly FilterApplier $filterApplier;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier */
    private readonly OrderApplier $orderApplier;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier */
    private readonly EagerLoadApplier $eagerLoadApplier;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\LimitApplier */
    private readonly LimitApplier $limitApplier;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier */
    private readonly ColumnProjectionApplier $columnProjectionApplier;

    /**
     * Constructor.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider  $metadataProvider
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $operatorRegistry
     * @return void
     */
    public function __construct(

        /** Source of query parameters for criteria resolution */
        protected Request $request,

        /** Resolves fields, eager loads, and counts from resource schemas */
        private readonly ResourceMetadataProvider $metadataProvider,

        /** Validates column searchability and relation existence */
        private readonly SchemaIntrospectionProvider $schemaIntrospector,

        /** Registry of filter operator handlers */
        private readonly OperatorRegistry $operatorRegistry,

    ) {
        $this->filterApplier           = new FilterApplier;
        $this->orderApplier            = new OrderApplier;
        $this->eagerLoadApplier        = new EagerLoadApplier;
        $this->limitApplier            = new LimitApplier;
        $this->columnProjectionApplier = new ColumnProjectionApplier(new SafetySetDeriver($this->schemaIntrospector));
    }

    /**
     * Apply the criteria to the given model.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    #[\Override]
    public function apply(Builder|Model $model): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        $query = $model instanceof Model ? $model::query() : $model;

        $surface = $this->buildQuerySurface($query->getModel());

        $query = $this->filterApplier->apply($query, $this->getFilters(), $this->schemaIntrospector, $this->operatorRegistry, $surface);
        $query = $this->eagerLoadApplier->apply($query, $this->metadataProvider, $this->resolveResource($query->getModel()), $this->getResourceType($query->getModel()));

        $query = $this->limitApplier->apply($query, $this->getLimit());

        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        return $this->applyOrderingAndProjection($query, $surface);
    }

    /**
     * Apply ordering, then narrow the base-table projection as the final step.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface  $surface
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    private function applyOrderingAndProjection(Builder $query, QuerySurface $surface): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        $query = $this->orderApplier->apply($query, $this->getOrder(), $surface);

        return $this->columnProjectionApplier->apply(
            $query,
            $this->metadataProvider,
            $this->resolveResource($query->getModel()),
            $this->getOrder(),
        );
    }

    /**
     * Get the filters to be applied to the query.
     *
     * @return array<string, mixed>|null
     */
    private function getFilters(): ?array
    {
        return ApiQuery::getFilters();
    }

    /**
     * Get the limit to be applied to the query.
     *
     * @return int|null
     */
    private function getLimit(): ?int
    {
        return ApiQuery::getLimit();
    }

    /**
     * Get the order to be applied to the query.
     *
     * @return array<string, string>
     */
    private function getOrder(): array
    {
        return ApiQuery::getOrder();
    }

    /**
     * Get the resource type for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     */
    private function getResourceType(Model $model): ?string
    {
        $resource = $this->resolveResource($model);

        if (!$resource || !is_subclass_of($resource, ApiResourceInterface::class)) {
            return null;
        }

        return $this->metadataProvider->getResourceType($resource);
    }

    /**
     * Build the declared query surface for the resolved resource, honouring the
     * configured posture. A model with no mapped resource yields an empty
     * surface, so the allowlist posture rejects every key.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function buildQuerySurface(Model $model): QuerySurface
    {
        $resource = $this->resolveResource($model);

        $schema = $resource && is_subclass_of($resource, ApiResourceInterface::class)
            ? SchemaCompiler::compile($resource)
            : null;

        $posture = Config::get('api-toolkit.repositories.query_posture', QuerySurface::POSTURE_ALLOWLIST);
        $reject  = Config::get('api-toolkit.repositories.reject_undeclared', true);

        return new QuerySurface(
            $schema?->getFilterableColumns()    ?? [],
            $schema?->getSortableColumns()      ?? [],
            $schema?->getTraversableRelations() ?? [],
            is_string($posture) ? $posture : QuerySurface::POSTURE_ALLOWLIST,
            (bool) $reject,
            $this->schemaIntrospector,
            $model,
        );
    }
}
