<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

use SineMacula\ApiToolkit\Query\PageSize;

/**
 * Immutable descriptor for a single resource's query surface.
 *
 * Carries the resource class, not its component name, so docs can group by
 * module.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QuerySurfaceDescriptor
{
    /**
     * Create a new query surface descriptor.
     *
     * @param  class-string  $resource
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor>  $columns
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor>  $relations
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryScopeDescriptor>  $scopes
     * @param  \SineMacula\ApiToolkit\Query\PageSize|null  $pageSize
     * @param  list<class-string>  $models
     */
    public function __construct(

        /** The resource class the surface was read from */
        public string $resource,

        /** The columns the resource answers a query on */
        public array $columns,

        /** The relations a filter may descend through */
        public array $relations = [],

        /** The named scopes a filter may invoke */
        public array $scopes = [],

        /** The resource's own page size bounds, where they differ from the configured ones */
        public ?PageSize $pageSize = null,

        /** The model classes the surface filters */
        public array $models = [],
    ) {}

    /**
     * Determine whether a filter may name any key of the surface.
     *
     * @return bool
     */
    public function isFilterable(): bool
    {
        $filterable = array_filter($this->columns, static fn (QueryColumnDescriptor $column): bool => $column->capability !== null);

        return $this->relations !== [] || $this->scopes !== [] || $filterable !== [];
    }

    /**
     * Determine whether a free-text search matches any column of the surface.
     *
     * @return bool
     */
    public function isSearchable(): bool
    {
        return array_filter($this->columns, static fn (QueryColumnDescriptor $column): bool => $column->strategy !== null) !== [];
    }
}
