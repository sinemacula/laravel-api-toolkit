<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Reads the query surface each registered resource declares, and each resource
 * a filter reaches through one.
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
     * Create a new query surface reader.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     */
    public function __construct(

        /** Resolves the relations a filter may descend through */
        private SchemaIntrospectionProvider $introspector,
    ) {}

    /**
     * Read one query surface descriptor per resource in the given map, then per
     * resource reached through a traversable relation.
     *
     * @param  array<class-string, class-string>  $resourceMap
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $registry
     * @return array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function read(array $resourceMap, OperatorRegistry $registry): array
    {
        $traversal = new RelationTraversal($this->introspector, $resourceMap);
        $queue     = [];
        $surfaces  = [];

        foreach ($resourceMap as $modelClass => $resourceClass) {
            $queue[] = [$resourceClass, $modelClass];
        }

        while ($queue !== []) {

            [$resourceClass, $modelClass] = array_shift($queue);

            if (isset($surfaces[$resourceClass])) {
                continue;
            }

            $edges = $traversal->edges($resourceClass, $modelClass);

            $surfaces[$resourceClass] = $this->readResource($resourceClass, $edges, $registry);

            foreach ($edges as $edge) {

                if ($edge->resource === null) {
                    continue;
                }

                $queue[] = [$edge->resource, $edge->model];
            }
        }

        return array_values($surfaces);
    }

    /**
     * Read a single resource's declared query surface.
     *
     * @param  class-string  $resourceClass
     * @param  list<\SineMacula\ApiToolkit\Schema\RelationEdge>  $edges
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $registry
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    private function readResource(string $resourceClass, array $edges, OperatorRegistry $registry): QuerySurfaceDescriptor
    {
        $compiled = SchemaCompiler::compile($resourceClass);
        $columns  = [];

        foreach ($compiled->getFieldKeys() as $property) {

            $field = $compiled->getField($property);

            if ($field === null) {
                continue;
            }

            $columns = [...$columns, ...$this->readColumns($property, $field, $compiled, $registry)];
        }

        return new QuerySurfaceDescriptor($resourceClass, $columns, $this->readRelations($compiled, $edges));
    }

    /**
     * Read the relations a filter may descend through and what governs each.
     *
     * A relation the model does not define governs no nested key.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @param  list<\SineMacula\ApiToolkit\Schema\RelationEdge>  $edges
     * @return list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor>
     */
    private function readRelations(CompiledSchema $compiled, array $edges): array
    {
        $keyed = [];

        foreach ($edges as $edge) {
            $keyed[$edge->relation] = $edge;
        }

        $relations = [];

        foreach ($compiled->getTraversableRelations() as $relation) {

            $edge = $keyed[$relation] ?? null;

            $relations[] = new QueryRelationDescriptor($relation, $edge?->resource, $edge->polymorphic ?? false);
        }

        return $relations;
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
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $registry
     * @return list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor>
     */
    private function readColumns(string $property, CompiledFieldDefinition $field, CompiledSchema $compiled, OperatorRegistry $registry): array
    {
        $columns = [];

        foreach ($this->declaredColumns($field) as $column) {

            $descriptor = $this->readColumn($property, $column, $field, $compiled, $registry);

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
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $registry
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor|null
     */
    private function readColumn(string $property, string $column, CompiledFieldDefinition $field, CompiledSchema $compiled, OperatorRegistry $registry): ?QueryColumnDescriptor
    {
        $capability = $field->filterable === $column ? ($compiled->getFilterableColumns()[$column] ?? null) : null;
        $operators  = $capability        === null ? [] : $registry->tokensFor($capability);
        $sortable   = $field->sortable   === $column && in_array($column, $compiled->getSortableColumns(), true);
        $strategy   = $field->searchable === $column ? ($compiled->getSearchableColumns()[$column] ?? null) : null;

        if ($operators === []) {
            $capability = null;
        }

        if ($capability === null && !$sortable && $strategy === null) {
            return null;
        }

        return new QueryColumnDescriptor(
            property         : $property,
            column           : $column,
            capability       : $capability,
            operators        : $operators,
            sortable         : $sortable,
            unindexedReason  : $sortable ? $field->unindexedReason : null,
            strategy         : $strategy,
            filterConditional: $capability !== null && array_key_exists($column, $compiled->getConditionalFilterKeys()),
            sortConditional  : $sortable            && array_key_exists($column, $compiled->getConditionalSortKeys()),
        );
    }
}
