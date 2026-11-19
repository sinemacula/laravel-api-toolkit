<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Config\ResourceSettings;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral;
use SineMacula\ApiToolkit\Repositories\Concerns\ResolvesResource;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\ColumnProjectionApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterValueGuard;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\LimitApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\QueryCostGuard;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SoftDeleteVisibilityApplier;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
use SineMacula\ApiToolkit\Schema\SafetySetDeriver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Search\IndexProof;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use SineMacula\ApiToolkit\Search\SearchTerm;
use SineMacula\Repositories\Contracts\CriteriaInterface;

/**
 * Applies the parsed API query to an Eloquent query.
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

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier */
    private readonly SearchApplier $searchApplier;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate */
    private readonly RelationTrashedGate $relationTrashedGate;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\QueryCostGuard */
    private readonly QueryCostGuard $queryCostGuard;

    /** @var object|null The identity of the API query these criteria apply */
    private ?object $queryToken;

    /**
     * Constructor.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider  $metadataProvider
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $operatorRegistry
     * @param  \SineMacula\ApiToolkit\Search\SearchDriverRegistry  $searchDriverRegistry
     * @param  \SineMacula\ApiToolkit\Cache\MetadataCacheWriter  $metadataCacheWriter
     * @param  bool  $filtersOnly
     * @return void
     */
    public function __construct(

        /** Source of query parameters for criteria resolution */
        protected Request $request,

        /** Resolves fields, eager loads, and aggregates from resource schemas */
        private readonly ResourceMetadataProvider $metadataProvider,

        /** Resolves model columns and relation existence */
        private readonly SchemaIntrospectionProvider $schemaIntrospector,

        /** Registry of filter operator handlers */
        private readonly OperatorRegistry $operatorRegistry,

        /** Registry of per-connection search drivers */
        private readonly SearchDriverRegistry $searchDriverRegistry,

        /** Writes resolved resource metadata to the persistent cache */
        private readonly MetadataCacheWriter $metadataCacheWriter,

        /** Whether to apply only the filters, search and trashed */
        private readonly bool $filtersOnly = false,
    ) {
        $this->relationTrashedGate         = new RelationTrashedGate($this->schemaIntrospector, $this->request, ResourceSettings::resourceMap());
        $this->filterApplier               = new FilterApplier(new FilterValueGuard(new ColumnLiteral($this->schemaIntrospector)), $this->relationTrashedGate);
        $this->searchApplier               = new SearchApplier($this->searchDriverRegistry, new IndexProof($this->metadataCacheWriter));
        $this->orderApplier                = new OrderApplier($this->schemaIntrospector, $this->searchApplier);
        $this->eagerLoadApplier            = new EagerLoadApplier;
        $this->limitApplier                = new LimitApplier;
        $this->columnProjectionApplier     = new ColumnProjectionApplier(new SafetySetDeriver($this->schemaIntrospector), $this->schemaIntrospector);
        $this->softDeleteVisibilityApplier = new SoftDeleteVisibilityApplier;
        $this->queryCostGuard              = new QueryCostGuard;
        $this->queryToken                  = ApiQuery::token();
    }

    /**
     * Apply the criteria to the given model.
     *
     * In filters-only mode the order, limit, fields and aggregates are neither
     * applied nor checked.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnservableSearchException
     */
    #[\Override]
    public function apply(Builder|Model $model): Builder
    {
        $this->bindQuery();

        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        $query = $model instanceof Model ? $model::query() : $model;

        if ($this->filtersOnly) {
            return $this->applyRowSelection($query, $this->buildQuerySurface($query->getModel()));
        }

        $resourceType = $this->getResourceType($query->getModel());

        $this->queryCostGuard->guard();

        $surface = $this->buildQuerySurface($query->getModel());
        $query   = $this->applyRowSelection($query, $surface);

        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        $query = $this->limitApplier->apply($query, $this->getLimit());
        $query = $this->applyOrderingAndProjection($query, $surface);

        // After the projection, so the aggregate subselects add to its columns
        return $this->eagerLoadApplier->apply(
            $query,
            $this->metadataProvider,
            $this->resolveResource($query->getModel()),
            $resourceType,
            $this->relationTrashedGate,
        );
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
     * Bind the criteria to the current API query, refusing to apply without one
     * or under another.
     *
     * Criteria composed before the query is parsed, as by a controller built
     * ahead of route-level parsing, bind to the first query they apply under.
     *
     * @return void
     *
     * @throws \LogicException
     */
    private function bindQuery(): void
    {
        $token = ApiQuery::token();

        if ($token === null) {
            throw new \LogicException('API criteria need a parsed API query: apply them inside a request handled by ParseApiQuery, or inside ApiQuery::using().');
        }

        $this->queryToken ??= $token;

        if ($this->queryToken !== $token) {
            throw new \LogicException('API criteria were composed under a different API query: compose and apply them inside the same request or ApiQuery::using() call.');
        }
    }

    /**
     * Apply the row predicates: soft-delete visibility, search and the grouped
     * filters.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface  $surface
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnservableSearchException
     */
    private function applyRowSelection(EloquentBuilder $query, QuerySurface $surface): EloquentBuilder
    {
        $resource = $this->resolveResource($query->getModel());

        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        $query = $this->softDeleteVisibilityApplier->apply($query, $resource, $this->request);
        $query = $this->searchApplier->apply($query, $this->getSearch(), $resource);

        return $this->applyGroupedFilters($query, $surface);
    }

    /**
     * Apply the filter expression inside a nested WHERE group.
     *
     * Grouped so a root `$or` cannot escape constraints ANDed on afterwards.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface  $surface
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function applyGroupedFilters(EloquentBuilder $query, QuerySurface $surface): EloquentBuilder
    {
        $query->where(function (EloquentBuilder $group) use ($surface): void {
            $this->filterApplier->apply($group, $this->getFilters(), $this->operatorRegistry, $surface);
        });

        return $query;
    }

    /**
     * Apply ordering, then narrow the base-table projection.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface  $surface
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException
     */
    private function applyOrderingAndProjection(Builder $query, QuerySurface $surface): Builder
    {
        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        $query = $this->orderApplier->apply($query, $this->getOrder(), $surface, $this->getSearch(), $this->resolveResource($query->getModel()));

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
     * Get the search term to be applied to the query.
     *
     * @return \SineMacula\ApiToolkit\Search\SearchTerm|null
     */
    private function getSearch(): ?SearchTerm
    {
        return ApiQuery::getSearch();
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
     * @return array<string, 'asc'|'desc'>
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
     * Build the query surface the request may use for the resolved resource.
     *
     * An unmapped model yields an empty surface, so every key is rejected.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function buildQuerySurface(Model $model): QuerySurface
    {
        $resource = $this->resolveResource($model);

        return new QuerySurface(
            $resource && is_subclass_of($resource, ApiResourceInterface::class) ? SchemaCompiler::compile($resource) : null,
            new RelationTraversal($this->schemaIntrospector, ResourceSettings::resourceMap()),
            new CallerAdmission($this->request, new GuardEvaluator),
            $this->operatorRegistry,
        );
    }
}
