<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Reads the query surface each registered resource declares.
 *
 * Compiles every resource in the map and reports, per column, what the resource
 * offers a client: the capability a filter is held to, whether an order is
 * accepted and whether an index holds it, and the strategy a free-text search
 * matches by. Each part is taken from the compiled schema's own column maps,
 * which are the maps the request-time gates read, so a declaration the gates do
 * not hold is never reported and the surface cannot claim a column the request
 * would reject. A column answering none of the three is left out entirely
 * rather than reported as an empty offer.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QuerySurfaceReader
{
    /**
     * Read one query surface descriptor per resource in the given map, in
     * registry order.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function read(array $resourceMap): array
    {
        $surfaces = [];

        foreach ($resourceMap as $resourceClass) {
            $surfaces[] = $this->readResource($resourceClass);
        }

        return $surfaces;
    }

    /**
     * Read a single resource's declared query surface.
     *
     * @param  class-string  $resourceClass
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    private function readResource(string $resourceClass): QuerySurfaceDescriptor
    {
        $compiled = SchemaCompiler::compile($resourceClass);
        $columns  = [];

        foreach ($compiled->getFieldKeys() as $property) {

            $field = $compiled->getField($property);

            if ($field === null) {
                continue;
            }

            $column = $this->readColumn($property, $field, $compiled);

            if ($column === null) {
                continue;
            }

            $columns[] = $column;
        }

        return new QuerySurfaceDescriptor($resourceClass, $columns, $compiled->getTraversableRelations());
    }

    /**
     * Read what one field offers a query, or null when it offers nothing.
     *
     * Every declaration a field makes names the field's own column, so any of
     * the three it declared names the same one. What that column answers is
     * then read from the compiled maps rather than from the declaration, so a
     * declaration the maps do not carry reports nothing and the surface cannot
     * offer a column the request would reject.
     *
     * @param  string  $property
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor|null
     */
    private function readColumn(string $property, CompiledFieldDefinition $field, CompiledSchema $compiled): ?QueryColumnDescriptor
    {
        $column = $field->filterable ?? $field->sortable ?? $field->searchable;

        if ($column === null) {
            return null;
        }

        $capability = $compiled->getFilterableColumns()[$column] ?? null;
        $sortable   = in_array($column, $compiled->getSortableColumns(), true);
        $strategy   = $compiled->getSearchableColumns()[$column] ?? null;

        if ($capability === null && !$sortable && $strategy === null) {
            return null;
        }

        return new QueryColumnDescriptor(
            property       : $property,
            column         : $column,
            capability     : $capability,
            sortable       : $sortable,
            unindexedReason: $field->unindexedReason,
            strategy       : $strategy,
        );
    }
}
