<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Declare the response body of a non-resource action.
 *
 * Placed on an action method, this directive names the class describing the
 * inner `data` shape of the success response, so a route that does not go
 * through the resource pipeline can still document a concrete body rather than
 * the opaque undocumented marker. Each named class is either a resource whose
 * registered component schema is referenced, or a self-describing class
 * declaring its own JSON-Schema fragment. Naming a list of classes documents a
 * response that answers with any one of their shapes, exported as a `oneOf`.
 * The collection flag wraps the shape in the collection envelope instead of the
 * single envelope, so a union then describes each item of the list.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class ResponseSchema
{
    /** @var non-empty-list<class-string> The classes describing the inner data shapes of the response. */
    public array $schemas;

    /**
     * Create a new response-schema directive.
     *
     * @param  array<array-key, mixed>|string  $schema
     * @param  bool  $collection
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(

        // The class, or list of classes, describing the inner data shape
        array|string $schema,

        /** Whether the data shape is a collection rather than a single item. */
        public bool $collection = false,
    ) {
        $classes = is_array($schema) ? $schema : [$schema];

        if ($classes === []) {
            throw new \InvalidArgumentException('A response schema must name at least one class');
        }

        foreach ($classes as $class) {
            if (!is_string($class)) {
                throw new \InvalidArgumentException('A response schema must name each class as a string');
            }
        }

        /** @var non-empty-list<class-string> $schemas */
        $schemas = array_values($classes);

        $this->schemas = $schemas;
    }
}
