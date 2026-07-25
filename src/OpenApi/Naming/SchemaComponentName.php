<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Naming;

/**
 * Derives the OpenAPI component schema name for a resource class.
 *
 * The class basename has its trailing "Resource" suffix removed, so
 * UserResource becomes the component name User. Every builder that references a
 * resource's component resolves the name through here, so the convention lives
 * in one place rather than being restated per builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SchemaComponentName
{
    /**
     * Derive the PascalCase component schema name from a resource class.
     *
     * @param  class-string  $resourceClass
     * @return string
     */
    public static function fromResource(string $resourceClass): string
    {
        $position = strrpos($resourceClass, '\\');
        $basename = $position === false ? $resourceClass : substr($resourceClass, $position + 1);

        return preg_replace('/Resource$/', '', $basename) ?? $basename;
    }
}
