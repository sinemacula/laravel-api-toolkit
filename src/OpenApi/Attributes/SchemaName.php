<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

/**
 * Override the OpenAPI component schema name derived for a resource.
 *
 * The toolkit derives a component name from the class basename with its
 * trailing "Resource" suffix removed, so two distinct resources sharing a
 * basename across namespaces would otherwise derive the same name and collide.
 * Placed on a resource class, this directive fixes a distinct name so both
 * schemas survive in the document.
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
