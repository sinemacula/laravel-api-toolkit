<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Derives a tabular export schema from the resource's compiled field
 * definitions.
 *
 * Intended for use on {@see \SineMacula\ApiToolkit\Http\Resources\ApiResource}
 * subclasses that also implement
 * {@see \SineMacula\Exporter\Contracts\ProvidesTabularExport}. When mixed in,
 * the `tabular()` method is satisfied for free: it compiles the resource schema
 * and maps each non-relation field to a typed Column. Accessor and computed
 * fields are resolved through a fresh resource instance, and plain scalar
 * fields fall through to the default raw-attribute read via `data_get`. The
 * resource's per-field guards and transformers are NOT applied to exported
 * values: a guarded or transformed field still emits its underlying attribute
 * or accessor result. Use the exporter's `->visible()` gate to keep a column
 * out of an export; a schema-declared guard does not.
 *
 * The schema returned is request-aware so column visibility and field
 * selection can be narrowed by the active API query.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @phpstan-require-extends \SineMacula\ApiToolkit\Http\Resources\ApiResource
 */
trait DerivesTabularSchema
{
    /**
     * Derive the tabular schema from the resource's compiled field definitions.
     *
     * Compiles the resource schema and builds one Column per non-relation
     * field. When a field set is active on the current API query, only the
     * requested fields are included, mirroring what the JSON response returns.
     * Relation fields are skipped because they cannot be represented as scalar
     * cells without an explicit expansion policy.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \SineMacula\Exporter\Schema\TabularSchema
     */
    public function tabular(Request $request): TabularSchema
    {
        /** @var class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource> $resourceClass */
        $resourceClass = static::class;

        $schema    = SchemaCompiler::compile($resourceClass);
        $requested = ApiQuery::getFields(static::getResourceType());
        $allKeys   = $schema->getFieldKeys();
        $fieldKeys = $requested !== null ? array_values(array_intersect($requested, $allKeys)) : $allKeys;

        $columns = [];

        foreach ($fieldKeys as $key) {

            $definition = $schema->getField($key);

            if ($definition === null || $definition->relation !== null) {
                continue;
            }

            $columns[] = $this->buildTabularColumn($key, $definition, $resourceClass);
        }

        return new DerivedTabularSchema($request, $columns);
    }

    /**
     * Build a single tabular column for the given field definition.
     *
     * Routes accessor and computed fields through a fresh resource instance so
     * the field's own compute or accessor closure runs, and plain scalar fields
     * use the default raw-attribute read via data_get on the underlying model.
     * The resource's per-field guards and transformers are NOT applied here, so
     * the column emits the raw resolved value; use the exporter's `->visible()`
     * gate to exclude a column from an export.
     *
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resourceClass
     * @return \SineMacula\Exporter\Schema\Column
     */
    private function buildTabularColumn(string $key, CompiledFieldDefinition $definition, string $resourceClass): Column
    {
        if (is_string($definition->accessor)) {
            return Column::make($key)->fromModel($definition->accessor);
        }

        $resolver = $this->columnResolverFor($definition, $resourceClass);

        return $resolver !== null ? Column::make($key)->resolveUsing($resolver) : Column::make($key);
    }

    /**
     * Build the closure used to resolve a column value through a resource
     * instance, or null when no resource-mediated resolution is needed.
     *
     * Combines callable compute and callable accessor into one code path since
     * both are invoked with the resource and request by ValueResolver.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resourceClass
     * @return \Closure|null
     */
    private function columnResolverFor(CompiledFieldDefinition $definition, string $resourceClass): ?\Closure
    {
        // String compute: a named method called on the resource instance.
        if (is_string($definition->compute)) {
            $method = $definition->compute;
            return static function ($item, $request) use ($resourceClass, $method): mixed {
                $resource = new $resourceClass($item);
                return method_exists($resource, $method) ? $resource->{$method}($request) : null; // @phpstan-ignore method.dynamicName
            };
        }

        // Callable compute and accessor are both invoked with the resource.
        if (is_callable($definition->compute)) {
            $callable = \Closure::fromCallable($definition->compute);
        } elseif (is_callable($definition->accessor)) {
            $callable = \Closure::fromCallable($definition->accessor);
        } else {
            return null;
        }

        return static fn ($item, $request): mixed => $callable(new $resourceClass($item), $request);
    }
}
