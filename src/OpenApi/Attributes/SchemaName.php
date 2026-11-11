<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Override the OpenAPI component schema name derived for a resource.
 *
 * Resolves collisions between resources sharing a basename across namespaces.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class SchemaName
{
    /**
     * Create a new schema-name override.
     *
     * @param  string  $name
     */
    public function __construct(

        /** The component schema name to use for the resource. */
        public string $name,
    ) {}
}
