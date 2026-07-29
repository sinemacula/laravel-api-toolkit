<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Naming;

use SineMacula\ApiToolkit\OpenApi\Attributes\SchemaName;

/**
 * Derives the OpenAPI component schema name for a resource class.
 *
 * A #[SchemaName] override on the class wins outright; otherwise the class
 * basename has its trailing "Resource" suffix removed, so UserResource becomes
 * the component name User. Every builder that references a resource's component
 * resolves the name through here, so the convention lives in one place rather
 * than being restated per builder.
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
        return self::override($resourceClass) ?? self::fromBasename($resourceClass);
    }

    /**
     * Read a non-empty #[SchemaName] override from the class, or null when the
     * class carries no override, declares an empty name, or cannot be
     * reflected.
     *
     * @param  class-string  $resourceClass
     * @return string|null
     */
    private static function override(string $resourceClass): ?string
    {
        if (!class_exists($resourceClass)) {
            return null;
        }

        $attributes = (new \ReflectionClass($resourceClass))->getAttributes(SchemaName::class);

        if ($attributes === []) {
            return null;
        }

        $name = $attributes[0]->newInstance()->name;

        return $name === '' ? null : $name;
    }

    /**
     * Derive the component name from the class basename with its trailing
     * "Resource" suffix removed.
     *
     * @param  class-string  $resourceClass
     * @return string
     */
    private static function fromBasename(string $resourceClass): string
    {
        $position = strrpos($resourceClass, '\\');
        $basename = $position === false ? $resourceClass : substr($resourceClass, $position + 1);

        return preg_replace('/Resource$/', '', $basename) ?? $basename;
    }
}
