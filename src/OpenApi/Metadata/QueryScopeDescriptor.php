<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Metadata;

/**
 * Immutable descriptor for one named scope a filter may invoke.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class QueryScopeDescriptor
{
    /**
     * Create a new query scope descriptor.
     *
     * @param  string  $name
     * @param  string|null  $description
     * @param  array<string, bool>  $arguments
     * @param  bool  $conditional
     */
    public function __construct(

        /** The name a filter invokes the scope by */
        public string $name,

        /** What the scope matches, if described */
        public ?string $description = null,

        /** The arguments the scope takes, each mapped to whether it is required */
        public array $arguments = [],

        /** Whether only some callers may invoke the scope */
        public bool $conditional = false,
    ) {}
}
