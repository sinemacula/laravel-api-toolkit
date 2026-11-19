<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

/**
 * Immutable descriptor for one relation a filter may descend through.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QueryRelationDescriptor
{
    /**
     * Create a new query relation descriptor.
     *
     * @param  string  $relation
     * @param  class-string|null  $resource
     * @param  bool  $polymorphic
     * @param  bool  $conditional
     */
    public function __construct(

        /** The relation name a filter descends through */
        public string $relation,

        /** The resource governing the keys under the relation, or null when none does */
        public ?string $resource = null,

        /** Whether each concrete type of the relation governs its own keys */
        public bool $polymorphic = false,

        /** Whether only some callers may descend through the relation */
        public bool $conditional = false,
    ) {}
}
