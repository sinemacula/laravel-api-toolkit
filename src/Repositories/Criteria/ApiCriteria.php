<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Concerns\ResolvesResource;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\LimitApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SoftDeleteVisibilityApplier;
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

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SoftDeleteVisibilityApplier */
    private readonly SoftDeleteVisibilityApplier $softDeleteVisibilityApplier;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate */
    private readonly RelationTrashedGate $relationTrashedGate;

    /**
     * Constructor.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider  $metadataProvider
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $operatorRegistry
     * @param  \SineMacula\ApiToolkit\Cache\MetadataCacheWriter  $metadataCacheWriter
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

        /** Writes resolved resource metadata to the persistent cache */
        private readonly MetadataCacheWriter $metadataCacheWriter,
    ) {
        $this->filterApplier               = new FilterApplier;
        $this->orderApplier                = new OrderApplier;
        $this->eagerLoadApplier            = new EagerLoadApplier;
        $this->limitApplier                = new LimitApplier;
        $this->columnProjectionApplier     = new ColumnProjectionApplier(new SafetySetDeriver($this->schemaIntrospector));
        $this->softDeleteVisibilityApplier = new SoftDeleteVisibilityApplier;

        $resourceMap = Config::get('api-toolkit.resources.resource_map', []);

        $this->relationTrashedGate = new RelationTrashedGate(
            $this->schemaIntrospector,
            $this->request,
            is_array($resourceMap) ? $resourceMap : [],
        );
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

        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        $query = $this->softDeleteVisibilityApplier->apply($query, $this->resolveResource($query->getModel()), $this->request);

        $query = $this->applyGroupedFilters($query, $surface);
        $query = $this->eagerLoadApplier->apply(
            $query,
            $this->metadataProvider,
            $this->resolveResource($query->getModel()),
            $this->getResourceType($query->getModel()),
            $this->relationTrashedGate,
        );

        $query = $this->limitApplier->apply($query, $this->getLimit());

        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        return $this->applyOrderingAndProjection($query, $surface);
    }

    /**
     * Get the metadata cache writer used by the ResolvesResource concern.
     *
     * @return \SineMacula\ApiToolkit\Cache\MetadataCacheWriter
     */
    #[\Override]
    protected function metadataCacheWriter(): MetadataCacheWriter
    {
        return $this->metadataCacheWriter;
    }

    /**
     * Apply the filter expression inside a nested WHERE group.
     *
     * The group stops a root-level `$or` escaping a constraint the caller ANDs
     * onto the query afterwards, such as a tenant or security scope. Ungrouped,
     * SQL `AND` binds tighter than a root-level `OR`, so that constraint would
     * bind to only one disjunct. An empty filter set yields an empty group,
     * which the builder discards.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface  $surface
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function applyGroupedFilters(EloquentBuilder $query, QuerySurface $surface): EloquentBuilder
    {
        $query->where(function (EloquentBuilder $group) use ($surface): void {
            $this->filterApplier->apply($group, $this->getFilters(), $this->schemaIntrospector, $this->operatorRegistry, $surface);
        });

        return $query;
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

        $posture     = Config::get('api-toolkit.repositories.query_posture', QuerySurface::POSTURE_ALLOWLIST);
        $resourceMap = Config::get('api-toolkit.resources.resource_map', []);

        return new QuerySurface(
            $schema?->getFilterableColumns()    ?? [],
            $schema?->getSortableColumns()      ?? [],
            $schema?->getTraversableRelations() ?? [],
            is_string($posture) ? $posture : QuerySurface::POSTURE_ALLOWLIST,
            $this->schemaIntrospector,
            $model,
            is_array($resourceMap) ? $resourceMap : [],
        );
    }
}
