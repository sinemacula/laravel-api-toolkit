<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Config\ResourceSettings;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Schema\ColumnNarrower;
use SineMacula\ApiToolkit\Schema\ColumnProjection;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SafetySetDeriver;

/**
 * Applies base-table column narrowing to an Eloquent query builder.
 *
 * Narrows only when every resolved field provably reads real table columns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ColumnProjectionApplier
{
    /**
     * Create a new column projection applier instance.
     *
     * @param  \SineMacula\ApiToolkit\Schema\SafetySetDeriver  $safetySetDeriver
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $schemaIntrospector
     */
    public function __construct(

        /** Derives the per-model safety set a narrowed query must retain. */
        private SafetySetDeriver $safetySetDeriver,

        /** Reads the real column listing every narrowed column is checked against. */
        private SchemaIntrospectionProvider $schemaIntrospector,
    ) {}

    /**
     * Apply base-table column narrowing to the query when safe and enabled.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  \SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider  $metadataProvider
     * @param  string|null  $resourceClass
     * @param  array<string, string>  $order
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function apply(Builder $query, ResourceMetadataProvider $metadataProvider, ?string $resourceClass, array $order): Builder
    {
        if (
            !ResourceSettings::shouldNarrowColumns()
            || $resourceClass === null
            || !is_subclass_of($resourceClass, ApiResourceInterface::class)
        ) {
            return $query;
        }

        $fields = $this->resolveFields($metadataProvider, $resourceClass);

        if (empty($fields)) {
            return $query;
        }

        $this->narrow($query, $metadataProvider, $resourceClass, $fields, $order);

        return $query;
    }

    /**
     * Narrow the select to the fields' columns and the safety set, or log why
     * it stays full.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  \SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider  $metadataProvider
     * @param  string  $resourceClass
     * @param  array<int, string>  $fields
     * @param  array<string, string>  $order
     * @return void
     */
    private function narrow(Builder $query, ResourceMetadataProvider $metadataProvider, string $resourceClass, array $fields, array $order): void
    {
        $model      = $query->getModel();
        $eagerLoads = [...$metadataProvider->eagerLoadMapFor($resourceClass, $fields), ...$query->getEagerLoads()];
        $safety     = $this->safetySetDeriver->derive($model, $eagerLoads, $this->orderColumns($order));

        if (!$safety->shouldNarrow()) {
            $this->logFallback($model, $resourceClass, ['relation' => $safety->reason()]);

            return;
        }

        $decision = (new ColumnNarrower)->decide(FieldColumnMapper::for($resourceClass), $fields, $safety->columns(), $model, $this->schemaIntrospector->getColumns($model));

        if ($decision->shouldNarrow()) {
            ColumnProjection::apply($query->getQuery(), $model->qualifyColumns($decision->columns()));
        } elseif ($decision->reason() !== null) {
            $this->logFallback($model, $resourceClass, ['field' => $decision->reason()]);
        }
    }

    /**
     * Log why the select was left unnarrowed.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $resourceClass
     * @param  array<string, string|null>  $cause
     * @return void
     */
    private function logFallback(Model $model, string $resourceClass, array $cause): void
    {
        Log::debug('Column narrowing fell back to a full select', ['model' => $model::class, 'resource' => $resourceClass, ...$cause]);
    }

    /**
     * Resolve the rendered field set for the resource class.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider  $metadataProvider
     * @param  string  $resourceClass
     * @return array<int, string>
     */
    private function resolveFields(ResourceMetadataProvider $metadataProvider, string $resourceClass): array
    {
        $resourceType = $metadataProvider->getResourceType($resourceClass);

        $fields = in_array(':all', ApiQuery::getFields($resourceType) ?? [], true)
            ? [...$metadataProvider->getAllFields($resourceClass), 'aggregates']
            : $metadataProvider->resolveFields($resourceClass);

        return [...$fields, ...$metadataProvider->getFixedFields($resourceClass)];
    }

    /**
     * Extract the order column names, excluding the order keywords.
     *
     * @param  array<string, string>  $order
     * @return array<int, string>
     */
    private function orderColumns(array $order): array
    {
        return array_values(array_filter(
            array_keys($order),
            static fn (string $column): bool => !OrderApplier::isKeyword($column),
        ));
    }
}
