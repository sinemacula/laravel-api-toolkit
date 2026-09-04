<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

/**
 * Immutable descriptor for a single resource's query surface.
 *
 * Carries the resource class the surface was read from, one column descriptor
 * per column the resource answers a query on in schema declaration order, and
 * the relation names a filter may descend through. The resource class is
 * carried rather than its component name so the documentation can group the
 * reference by the module the resource belongs to and derive the display name
 * the same way every other builder does.
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
