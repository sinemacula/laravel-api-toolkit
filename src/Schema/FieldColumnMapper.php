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

        foreach ($schema->getFields() as $field => $definition) {
            if ($definition->needs !== []) {
                $declared[$field] = $definition->needs;
            } elseif (self::isPlain($definition)) {
                $inferred[] = $field;
            } elseif (self::readsOnlyTheRelated($definition)) {
                $declared[$field] = [];
            }
        }

        $aggregates = $schema->getAggregateDefinitions();

        if ($aggregates !== [] && self::readsNoColumn($aggregates)) {
            $declared['aggregates'] = [];
        }

        return FieldColumnMap::make($declared, $inferred, $aggregates === [] ? $schema->getFieldKeys() : [...$schema->getFieldKeys(), 'aggregates']);
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

    /**
     * Determine whether a relation field reads only the related models, whose
     * parent-side key the safety set keeps.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $definition
     * @return bool
     */
    private static function readsOnlyTheRelated(CompiledFieldDefinition $definition): bool
    {
        if ($definition->relation === null || $definition->compute !== null || !is_string($definition->accessor ?? '')) {
            return false;
        }

        return $definition->guards === [] && $definition->transformers === [];
    }

    /**
     * Determine whether the aggregates payload reads no column, since a guard
     * or transformer receives the resource.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition>  $aggregates
     * @return bool
     */
    private static function readsNoColumn(array $aggregates): bool
    {
        foreach ($aggregates as $aggregate) {
            if ($aggregate->guards !== [] || $aggregate->transformers !== []) {
                return false;
            }
        }

        return true;
    }
}
