<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Reads the query surface each registered resource declares.
 *
 * Read from the compiled column maps the request-time gates use, so the surface
 * never claims a column a request would reject.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QuerySurfaceReader
{
    /**
     * Read one query surface descriptor per resource in the given map.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @param  array<int, string>  $vocabulary
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function read(array $resourceMap, array $vocabulary): array
    {
        $surfaces = [];

        foreach ($resourceMap as $resourceClass) {
            $surfaces[] = $this->readResource($resourceClass, $vocabulary);
        }

        return $surfaces;
    }

    /**
     * Read a single resource's declared query surface.
     *
     * @param  class-string  $resourceClass
     * @param  array<int, string>  $vocabulary
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    private function readResource(string $resourceClass, array $vocabulary): QuerySurfaceDescriptor
    {
        $compiled = SchemaCompiler::compile($resourceClass);
        $columns  = [];

        foreach ($compiled->getFieldKeys() as $property) {

            $field = $compiled->getField($property);

            if ($field === null) {
                continue;
            }

            $columns = [...$columns, ...$this->readColumns($property, $field, $compiled, $vocabulary)];
        }

        return new QuerySurfaceDescriptor($resourceClass, $columns, $compiled->getTraversableRelations());
    }

    /**
     * Read what one field offers a query, one descriptor per column it names.
     *
     * A hand-written schema may name a different column for filter, order and
     * search.
     *
     * @param  string  $property
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @param  array<int, string>  $vocabulary
     * @return list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor>
     */
    private function readColumns(string $property, CompiledFieldDefinition $field, CompiledSchema $compiled, array $vocabulary): array
    {
        $columns = [];

        foreach ($this->declaredColumns($field) as $column) {

            $descriptor = $this->readColumn($property, $column, $field, $compiled, $vocabulary);

            if ($descriptor === null) {
                continue;
            }

            $columns[] = $descriptor;
        }

        return $columns;
    }

    /**
     * List the distinct columns the field declares.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return list<string>
     */
    private function declaredColumns(CompiledFieldDefinition $field): array
    {
        $columns = [];

        foreach ([$field->filterable, $field->sortable, $field->searchable] as $column) {

            if ($column === null) {
                continue;
            }

            $columns[$column] = true;
        }

        return array_keys($columns);
    }

    /**
     * Read what one column of one field offers a query, if anything.
     *
     * @param  string  $property
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @param  array<int, string>  $vocabulary
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor|null
     */
    private function readColumn(string $property, string $column, CompiledFieldDefinition $field, CompiledSchema $compiled, array $vocabulary): ?QueryColumnDescriptor
    {
        $capability = $field->filterable === $column ? ($compiled->getFilterableColumns()[$column] ?? null) : null;
        $operators  = $capability        === null ? [] : DispatchableOperators::forCapability($capability, $vocabulary);
        $sortable   = $field->sortable   === $column && in_array($column, $compiled->getSortableColumns(), true);
        $strategy   = $field->searchable === $column ? ($compiled->getSearchableColumns()[$column] ?? null) : null;

        if ($operators === []) {
            $capability = null;
        }

        if ($capability === null && !$sortable && $strategy === null) {
            return null;
        }

        return new QueryColumnDescriptor(
            property       : $property,
            column         : $column,
            capability     : $capability,
            operators      : $operators,
            sortable       : $sortable,
            unindexedReason: $sortable ? $field->unindexedReason : null,
            strategy       : $strategy,
        );
    }
}
