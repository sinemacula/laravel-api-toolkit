<?php

namespace SineMacula\ApiToolkit\Schema;

/**
 * Builds a per-resource-type FieldColumnMap from a compiled schema.
 *
 * Classifies each field as provably column-mapped or opaque: scalar fields map
 * to their own column, needs-carrying fields map to their declared columns, and
 * accessor/compute/relation/guarded/transformed fields without needs are flagged
 * unmapped. Caches one map per resource class so it is built once and reused
 * across requests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
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
        $columns = [];
        $mapped  = [];

        foreach ($schema->getFieldKeys() as $field) {
            $definition = $schema->getField($field);

            if ($definition === null || !self::isProvablyMapped($definition)) {
                continue;
            }

            $columns[$field] = self::columnsForDefinition($field, $definition);
            $mapped[]        = $field;
        }

        return FieldColumnMap::make($columns, $mapped);
    }

    /**
     * Clear the per-resource-class field-column map cache.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Determine whether a field is provably mapped to base-table columns.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @return bool
     */
    private static function isProvablyMapped(CompiledFieldDefinition $definition): bool
    {
        if ($definition->needs !== []) {
            return true;
        }

        if ($definition->accessor !== null || $definition->compute !== null || $definition->relation !== null) {
            return false;
        }

        return $definition->guards === [] && $definition->transformers === [];
    }

    /**
     * Resolve the declared columns for a provably-mapped field.
     *
     * @param  string  $field
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @return array<int, string>
     */
    private static function columnsForDefinition(string $field, CompiledFieldDefinition $definition): array
    {
        return $definition->needs !== [] ? $definition->needs : [$field];
    }
}
