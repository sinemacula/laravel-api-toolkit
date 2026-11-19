<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldPresence;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\Query\PageSize;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\CompiledScopeDefinition;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Reads the query surface each registered resource declares, and each resource
 * a filter or an order reaches through one.
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
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser  $normaliser
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\FieldPresence  $presence
     */
    public function __construct(

        /** Resolves the relations a filter may descend through */
        private SchemaIntrospectionProvider $introspector,

        /** Flattens a scope argument's rules into tokens */
        private RuleNormaliser $normaliser = new RuleNormaliser,

        /** Reads whether a scope argument's rules require it */
        private FieldPresence $presence = new FieldPresence,
    ) {}

    /**
     * Read one query surface descriptor per resource in the given map, then per
     * resource reached through a traversable or sortable relation.
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
        $edges     = [];
        $models    = [];

        foreach ($resourceMap as $modelClass => $resourceClass) {
            $queue[] = [$resourceClass, $modelClass];
        }

        while ($queue !== []) {

            [$resourceClass, $modelClass] = array_shift($queue);

            $models[$resourceClass][$modelClass] = true;

            if (isset($edges[$resourceClass])) {
                continue;
            }

            $edges[$resourceClass] = $traversal->edges($resourceClass, $modelClass);

            foreach ($edges[$resourceClass] as $edge) {

                if ($edge->resource === null) {
                    continue;
                }

                $queue[] = [$edge->resource, $edge->model];
            }
        }

        $surfaces = [];

        foreach ($edges as $resourceClass => $resourceEdges) {
            $surfaces[] = $this->readResource($resourceClass, $resourceEdges, $registry, array_keys($models[$resourceClass]));
        }

        return $surfaces;
    }

    /**
     * Read the query surface one resource declares over the given model.
     *
     * @param  class-string  $resourceClass
     * @param  class-string  $modelClass
     * @param  array<class-string, class-string>  $resourceMap
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $registry
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function readOne(string $resourceClass, string $modelClass, array $resourceMap, OperatorRegistry $registry): QuerySurfaceDescriptor
    {
        $edges = (new RelationTraversal($this->introspector, $resourceMap))->edges($resourceClass, $modelClass);

        return $this->readResource($resourceClass, $edges, $registry, [$modelClass]);
    }

    /**
     * Read a single resource's declared query surface.
     *
     * @param  class-string  $resourceClass
     * @param  list<\SineMacula\ApiToolkit\Schema\RelationEdge>  $edges
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $registry
     * @param  list<class-string>  $models
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     *
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    private function readResource(string $resourceClass, array $edges, OperatorRegistry $registry, array $models): QuerySurfaceDescriptor
    {
        $compiled = SchemaCompiler::compile($resourceClass);
        $columns  = [];

        foreach ($compiled->getFields() as $property => $field) {
            $columns = [...$columns, ...$this->readColumns($property, $field, $compiled, $registry)];
        }

        $columns = [...$columns, ...$this->readAggregateSorts($compiled), ...$this->readRelationSorts($compiled)];

        $pageSize = PageSize::for($resourceClass);

        return new QuerySurfaceDescriptor(
            $resourceClass,
            $columns,
            $this->readRelations($compiled, $edges),
            array_values(array_map(fn (CompiledScopeDefinition $scope): QueryScopeDescriptor => $this->readScope($scope, $models), $compiled->getScopes())),
            $pageSize->differsFrom(PageSize::for(null)) ? $pageSize : null,
            $models,
        );
    }

    /**
     * Read one named scope and whether each argument is required, by its rules
     * or by a parameter with no default on any model the surface filters.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledScopeDefinition  $scope
     * @param  list<class-string>  $models
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QueryScopeDescriptor
     */
    private function readScope(CompiledScopeDefinition $scope, array $models): QueryScopeDescriptor
    {
        $unbound   = $this->unboundArguments($scope, $models);
        $arguments = [];

        foreach ($scope->arguments as $argument) {
            $arguments[$argument] = in_array($argument, $unbound, true) || $this->presence->isRequired($this->normaliser->normalise($scope->parameters[$argument] ?? null));
        }

        return new QueryScopeDescriptor($scope->name, $scope->description, $arguments, $scope->callerGuards !== []);
    }

    /**
     * List the parameters the scope's method declares with no default on any of
     * the models, which a call must therefore name.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledScopeDefinition  $scope
     * @param  list<class-string>  $models
     * @return list<string>
     */
    private function unboundArguments(CompiledScopeDefinition $scope, array $models): array
    {
        $unbound = [];

        foreach ($models as $modelClass) {

            $parameters = is_subclass_of($modelClass, Model::class) ? $scope->method($modelClass)?->getParameters() ?? [] : [];

            foreach (array_slice($parameters, 1) as $parameter) {

                if ($parameter->isVariadic() || $parameter->isDefaultValueAvailable()) {
                    continue;
                }

                $unbound[] = $parameter->getName();
            }
        }

        return $unbound;
    }

    /**
     * Read one order key per sortable aggregate.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @return list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor>
     */
    private function readAggregateSorts(CompiledSchema $compiled): array
    {
        $columns = [];

        foreach ($compiled->getSortableMetrics() as $key => $aggregate) {
            $columns[] = new QueryColumnDescriptor(
                property       : $key,
                column         : $key,
                sortable       : true,
                unindexedReason: $aggregate->unindexedReason,
                sortConditional: $aggregate->callerGuards !== [],
            );
        }

        return $columns;
    }

    /**
     * Read one order key per sortable column of the resource each sortable
     * relation renders.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @return list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    private function readRelationSorts(CompiledSchema $compiled): array
    {
        $columns = [];

        foreach ($compiled->getSortableRelations() as $relation => $declarers) {

            $resource = $compiled->getTraversableResource($relation) ?? '';

            if (!is_subclass_of($resource, ApiResourceInterface::class)) {
                continue;
            }

            $child   = SchemaCompiler::compile($resource);
            $guarded = $compiled->isConditionalSortRelation($relation);

            foreach ($this->sortableColumns($child) as $column => $property) {
                $columns[] = new QueryColumnDescriptor(
                    property       : $declarers[0] . '.' . $property,
                    column         : $relation . '.' . $column,
                    sortable       : true,
                    unindexedReason: $compiled->getField($declarers[0])?->sortThroughReason,
                    sortConditional: $guarded || array_key_exists($column, $child->getConditionalSortKeys()),
                );
            }
        }

        return $columns;
    }

    /**
     * Map each sortable column of a resource to its first declaring property.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @return array<string, string>
     */
    private function sortableColumns(CompiledSchema $compiled): array
    {
        $columns = [];

        foreach ($compiled->getFieldKeys() as $property) {

            $column = $compiled->getField($property)?->sortable;

            if ($column === null || !in_array($column, $compiled->getSortableColumns(), true)) {
                continue;
            }

            $columns[$column] ??= $property;
        }

        return $columns;
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

            $relations[] = new QueryRelationDescriptor(
                relation   : $relation,
                resource   : $edge?->resource,
                polymorphic: $edge->polymorphic ?? false,
                conditional: array_key_exists($relation, $compiled->getConditionalTraversalKeys()),
            );
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
