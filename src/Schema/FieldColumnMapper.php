<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Builds and caches a per-resource-type FieldColumnMap from a compiled schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
final class FieldColumnMapper
{
    /** @var array<string, \SineMacula\ApiToolkit\Schema\FieldColumnMap> */
    private static array $cache = [];

    /**
     * Build and cache the field-column map for the given resource class.
     *
     * @param  string  $resourceClass
     * @return \SineMacula\ApiToolkit\Schema\FieldColumnMap
     */
    public static function for(string $resourceClass): FieldColumnMap
    {
        return self::$cache[$resourceClass] ??= self::build(SchemaCompiler::compile($resourceClass));
    }

    /**
     * Build a field-column map directly from a compiled schema.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return \SineMacula\ApiToolkit\Schema\FieldColumnMap
     */
    public static function build(CompiledSchema $schema): FieldColumnMap
    {
        $declared = [];
        $inferred = [];

        foreach ($schema->getFieldKeys() as $field) {
            $definition = $schema->getField($field);

            if ($definition === null) {
                continue;
            }

            if ($definition->needs !== []) {
                $declared[$field] = $definition->needs;
            } elseif (self::isPlain($definition)) {
                $inferred[] = $field;
            }
        }

        return FieldColumnMap::make($declared, $inferred);
    }

    /**
     * Clear the per-resource-class field-column map cache.
     *
     * Field-column maps live for the worker, so a lifecycle flush leaves them;
     * this is for test isolation.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Determine whether a field is read straight off the model by its own key.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @return bool
     */
    private static function isPlain(CompiledFieldDefinition $definition): bool
    {
        if ($definition->accessor !== null || $definition->compute !== null || $definition->relation !== null) {
            return false;
        }

        return $definition->guards === [] && $definition->transformers === [];
    }
}
