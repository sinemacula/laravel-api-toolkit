<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Schema\ColumnNarrower;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SafetySetDeriver;

/**
 * Applies base-table column narrowing to an Eloquent query builder.
 *
 * Composes the resolved field set, the field-column map, and the per-model
 * safety set, asks the narrower for a decision, and applies a single `select()`
 * only when every resolved field is provably column-mapped. On every other path
 * the builder's columns are left untouched so the downstream default `'*'`
 * selection flows through unchanged. When an unmapped field forces that
 * fall-back, the offending field key is recorded at debug level so the silent
 * widening is diagnosable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ColumnProjectionApplier
{
    /**
     * Create a new column projection applier instance.
     *
     * @param  \SineMacula\ApiToolkit\Schema\SafetySetDeriver  $safetySetDeriver
     */
    public function __construct(

        /** Derives the per-model safety set a narrowed query must retain. */
        private readonly SafetySetDeriver $safetySetDeriver,
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
            !Config::get('api-toolkit.resources.narrow_columns', false)
            || $resourceClass === null
            || !is_subclass_of($resourceClass, ApiResourceInterface::class)
        ) {
            return $query;
        }

        $fields = $this->resolveFields($metadataProvider, $resourceClass);

        if (empty($fields)) {
            return $query;
        }

        $relationKeys = $this->relationNames($metadataProvider->eagerLoadMapFor($resourceClass, $fields));
        $safety       = $this->deriveSafetySet($query, $relationKeys, $order);
        $decision     = (new ColumnNarrower)->decide(FieldColumnMapper::for($resourceClass), $fields, $safety);

        if ($decision->shouldNarrow()) {
            $query->getQuery()->select($decision->columns());
        } elseif ($decision->reason() !== null) {
            // Surface the silent fall-back so a developer can see which field
            // forced a full select; debug level keeps it quiet in production.
            Log::debug('Column narrowing fell back to a full select', [
                'model'    => $query->getModel()::class,
                'resource' => $resourceClass,
                'field'    => $decision->reason(),
            ]);
        }

        return $query;
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

        return in_array(':all', ApiQuery::getFields($resourceType) ?? [], true)
            ? $metadataProvider->getAllFields($resourceClass)
            : $metadataProvider->resolveFields($resourceClass);
    }

    /**
     * Extract the base-model relation names from an eager-load map.
     *
     * The map mixes plain and extra relations - stored as list entries whose
     * relation path is the value - with scoped relations stored under a string
     * key carrying a constraint closure. The relation name is therefore taken
     * from the value for integer keys and from the key for string keys. Each
     * path is reduced to its first segment: the relation declared on the base
     * model, whose parent-side key the narrowed query must retain.
     *
     * @param  array<int|string, mixed>  $map
     * @return array<int, string>
     */
    private function relationNames(array $map): array
    {
        $names = [];

        foreach ($map as $key => $value) {

            $path = is_string($key) ? $key : $value;

            if (!is_string($path)) {
                continue;
            }

            $names[] = explode('.', $path)[0];
        }

        return $names;
    }

    /**
     * Derive the per-model safety set of columns the narrowed query must
     * retain.
     *
     * @param  \Illuminate\Contracts\Database\Eloquent\Builder  $query
     * @param  array<int, string>  $relationKeys
     * @param  array<string, string>  $order
     * @return array<int, string>
     */
    private function deriveSafetySet(Builder $query, array $relationKeys, array $order): array
    {
        return $this->safetySetDeriver->derive($query->getModel(), $relationKeys, $this->orderColumns($order));
    }

    /**
     * Extract the order column names, excluding the random-ordering keyword.
     *
     * @param  array<string, string>  $order
     * @return array<int, string>
     */
    private function orderColumns(array $order): array
    {
        return array_values(array_filter(
            array_keys($order),
            static fn (string $column): bool => $column !== OrderApplier::ORDER_BY_RANDOM,
        ));
    }
}
