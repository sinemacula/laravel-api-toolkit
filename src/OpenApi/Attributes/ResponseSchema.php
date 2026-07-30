<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Declare the response body of a non-resource action.
 *
 * Placed on an action method, this directive names the class describing the
 * inner `data` shape of the success response, so a route that does not go
 * through the resource pipeline can still document a concrete body rather than
 * the opaque undocumented marker. The named class is either a resource whose
 * registered component schema is referenced, or a self-describing class
 * declaring its own JSON-Schema fragment; the collection flag wraps the shape
 * in the collection envelope instead of the single envelope.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class ResponseSchema
{
    /**
     * Create a new response-schema directive.
     *
     * @param  string  $schema
     * @param  bool  $collection
     */
    public function __construct(

        /** The class describing the inner data shape of the response. */
        public string $schema,

        /** Whether the data shape is a collection rather than a single item. */
        public bool $collection = false,
    ) {}
}
