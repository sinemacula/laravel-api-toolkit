<?php

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Closure;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;

/**
 * Compiles raw resource schema arrays into typed CompiledSchema objects.
 *
 * Manages a per-class static cache so that each resource class is compiled
 * only once per request lifecycle.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SchemaCompiler
{
    /** @var array<string, \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema> */
    private static array $cache = [];

    /**
     * Compile and cache the schema for the given resource class.
     *
     * @param  string  $resourceClass
     * @return \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema
     */
    public static function compile(string $resourceClass): CompiledSchema
    {
        if (isset(self::$cache[$resourceClass])) {
            return self::$cache[$resourceClass];
        }

        $rawSchema = $resourceClass::schema();

        return self::$cache[$resourceClass] = self::buildCompiledSchema($rawSchema);
    }

    /**
     * Clear all cached compiled schemas.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Build a CompiledSchema from a raw schema array.
     *
     * Each entry is sorted into either a CompiledFieldDefinition or a
     * CompiledCountDefinition based on the presence of a count metric.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @return \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema
     */
    private static function buildCompiledSchema(array $rawSchema): CompiledSchema
    {
        /** @var array<string, \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition> $fields */
        $fields = [];

        /** @var array<string, \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition> $counts */
        $counts = [];

        foreach ($rawSchema as $schemaKey => $definition) {

            if (($definition['metric'] ?? null) === 'count') {
                $counts[self::resolveCountKey($schemaKey, $definition)] = self::buildCountDefinition($schemaKey, $definition);
                continue;
            }

            $fields[$schemaKey] = self::buildFieldDefinition($definition);
        }

        return new CompiledSchema($fields, $counts);
    }

    /**
     * Resolve the presentation key for a count definition.
     *
     * @param  string  $schemaKey
     * @param  array<string, mixed>  $definition
     * @return string
     */
    private static function resolveCountKey(string $schemaKey, array $definition): string
    {
        $key = $definition['key'] ?? null;

        if (is_string($key)) {
            return $key;
        }

        return str_starts_with($schemaKey, '__count__:') ? substr($schemaKey, 10) : $schemaKey;
    }

    /**
     * Build a CompiledCountDefinition from a raw definition array.
     *
     * @param  string  $schemaKey
     * @param  array<string, mixed>  $definition
     * @return \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition
     */
    private static function buildCountDefinition(string $schemaKey, array $definition): CompiledCountDefinition
    {
        $presentKey = self::resolveCountKey($schemaKey, $definition);
        $constraint = $definition['constraint'] ?? null;

        return new CompiledCountDefinition(
            presentKey: $presentKey,
            relation  : is_string($definition['relation'] ?? null) ? $definition['relation'] : $presentKey,
            constraint: $constraint instanceof Closure ? $constraint : null,
            isDefault : (bool) ($definition['default'] ?? false),
            guards    : $definition['guards'] ?? [],
        );
    }

    /**
     * Build a CompiledFieldDefinition from a raw definition array.
     *
     * @param  array<string, mixed>  $definition
     * @return \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition
     */
    private static function buildFieldDefinition(array $definition): CompiledFieldDefinition
    {
        $constraint = $definition['constraint'] ?? null;
        $relations  = (array) ($definition['relation'] ?? null);
        $relation   = isset($relations[0]) && is_string($relations[0]) ? $relations[0] : null;

        return new CompiledFieldDefinition(
            accessor    : $definition['accessor'] ?? null,
            compute     : $definition['compute'] ?? null,
            relation    : $relation,
            resource    : isset($definition['resource']) && is_string($definition['resource']) ? $definition['resource'] : null,
            fields      : $definition['fields'] ?? null,
            constraint  : $constraint instanceof Closure ? $constraint : null,
            extras      : (array) ($definition['extras'] ?? []),
            guards      : $definition['guards'] ?? [],
            transformers: $definition['transformers'] ?? [],
        );
    }
}
