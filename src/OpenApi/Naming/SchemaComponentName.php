<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Naming;

use SineMacula\ApiToolkit\OpenApi\Attributes\SchemaName;

/**
 * Derives the OpenAPI component schema name for a resource or enum class.
 *
 * A #[SchemaName] override on the class wins outright; otherwise a resource's
 * class basename has its trailing "Resource" suffix removed, so UserResource
 * becomes the component name User, while an enum keeps its class basename
 * verbatim. Every builder that references a resource's or enum's component
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
     * Derive the PascalCase component schema name from an enum class.
     *
     * A #[SchemaName] override wins outright; otherwise the enum keeps its
     * class basename verbatim, since an enum carries no "Resource" suffix to
     * strip.
     *
     * @param  class-string  $enumClass
     * @return string
     */
    public static function fromEnum(string $enumClass): string
    {
        return self::override($enumClass) ?? self::basename($enumClass);
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
        $basename = self::basename($resourceClass);

        return preg_replace('/Resource$/', '', $basename) ?? $basename;
    }

    /**
     * Extract the class basename, dropping any namespace qualifier.
     *
     * @param  class-string  $class
     * @return string
     */
    private static function basename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
