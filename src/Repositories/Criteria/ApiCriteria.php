<?php

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\LimitApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Repositories\Traits\ResolvesResource;
use SineMacula\Repositories\Contracts\CriteriaInterface;

/**
 * API criteria.
 *
 * Thin orchestrator that delegates filtering, eager loading, limiting, and
 * ordering to single-responsibility concern classes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class ApiCriteria implements CriteriaInterface
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

    /**
     * Constructor.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider  $metadataProvider
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @return void
     */
    public function __construct(

        /** Source of query parameters for criteria resolution */
        protected Request $request,

        /** Resolves fields, eager loads, and counts from resource schemas */
        private readonly ResourceMetadataProvider $metadataProvider,

        /** Validates column searchability and relation existence */
        private readonly SchemaIntrospectionProvider $schemaIntrospector,

    ) {
        $this->filterApplier    = new FilterApplier;
        $this->orderApplier     = new OrderApplier;
        $this->eagerLoadApplier = new EagerLoadApplier;
        $this->limitApplier     = new LimitApplier;
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
        $query = $model instanceof Model ? $model->query() : $model;

        $query = $this->filterApplier->apply($query, $this->getFilters(), $this->schemaIntrospector);
        $query = $this->eagerLoadApplier->apply($query, $this->metadataProvider, $this->resolveResource($query->getModel()), $this->getResourceType($query->getModel()));

        $query = $this->limitApplier->apply($query, $this->getLimit());

        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        return $this->orderApplier->apply($query, $this->getOrder(), $this->schemaIntrospector);
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
     * @return array
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

        if (!$resource || !is_subclass_of($resource, ApiResource::class)) {
            return null;
        }

        return $this->metadataProvider->getResourceType($resource);
    }
}
