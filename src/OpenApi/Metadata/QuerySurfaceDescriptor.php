<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

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
     * @param  array<int, string>  $relations
     */
    public function __construct(

        /** The resource class the surface was read from */
        public string $resource,

        /** The columns the resource answers a query on */
        public array $columns,

        /** The relation names a filter may descend through */
        public array $relations = [],
    ) {}
}
