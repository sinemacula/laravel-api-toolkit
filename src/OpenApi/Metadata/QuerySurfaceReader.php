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
 * offers a client: the capability a filter is held to together with the
 * operators that capability still answers, whether an order is accepted and
 * whether the resource exempted it from index backing, and the strategy a
 * free-text search matches by. Each part is taken from the compiled schema's
 * own column maps, which are the maps the request-time gates read, so a
 * declaration the gates do not hold is never reported and the surface cannot
 * claim a column the request would reject. A column answering none of the three
 * is left out entirely rather than reported as an empty offer.
 *
 * The three declarations a field makes are read independently rather than
 * collapsed into one column. A field built through the schema DSL names one
 * column in all three, but a hand-written schema may name a different column in
 * each, and reading them as one would attribute a capability or a strategy to a
 * column that never declared it. A field naming more than one column is
 * reported once per column, each carrying only what that column answers.
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
     * Read what one field offers a query, as one descriptor per column it names
     * and none where it names no column the compiled maps carry.
     *
     * What a column answers is read from the compiled maps rather than from the
     * declaration, so a declaration the maps do not carry reports nothing and
     * the surface cannot offer a column the request would reject.
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
     * List the distinct columns the field declares, in the order a filter, an
     * order, and a search are read.
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
     * Read what one column of one field offers a query, or null when the
     * compiled maps hold nothing for it.
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
