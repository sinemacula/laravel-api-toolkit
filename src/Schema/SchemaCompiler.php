<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Compiles raw resource schema arrays into typed CompiledSchema objects.
 *
 * Manages a per-class static cache so that each resource class is compiled only
 * once per request lifecycle.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
final class SchemaCompiler
{
    /** @var array<string, \SineMacula\ApiToolkit\Schema\CompiledSchema> */
    private static array $cache = [];

    /**
     * Compile and cache the schema for the given resource class.
     *
     * @param  string  $resourceClass
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public static function compile(string $resourceClass): CompiledSchema
    {
        if (isset(self::$cache[$resourceClass])) {
            return self::$cache[$resourceClass];
        }

        $rawSchema = $resourceClass::schema();

        self::assertValidConstraints($rawSchema, $resourceClass);

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
     * Assert that every constraint in the raw schema is a Closure or absent.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @param  string  $resourceClass
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    private static function assertValidConstraints(array $rawSchema, string $resourceClass): void
    {
        $errors = [];

        foreach ($rawSchema as $key => $definition) {

            $constraint = $definition['constraint'] ?? null;

            if ($constraint === null || $constraint instanceof \Closure) {
                continue;
            }

            $errors[] = new SchemaValidationError(
                resourceClass: $resourceClass,
                fieldKey: $key,
                defect: 'Constraint must be a Closure',
            );
        }

        if ($errors !== []) {
            throw new InvalidSchemaException($errors);
        }
    }

    /**
     * Build a CompiledSchema from a raw schema array.
     *
     * Each entry is sorted into a CompiledFieldDefinition, a
     * CompiledCountDefinition, or a CompiledAggregateDefinition based on the
     * metric value present in the definition.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private static function buildCompiledSchema(array $rawSchema): CompiledSchema
    {
        /** @var array<string, \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition> $fields */
        $fields = [];

        /** @var array<string, \SineMacula\ApiToolkit\Schema\CompiledCountDefinition> $counts */
        $counts = [];

        /** @var array<string, \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition> $aggregates */
        $aggregates = [];

        $filterable  = [];
        $sortable    = [];
        $traversable = [];

        foreach ($rawSchema as $schemaKey => $definition) {

            $metric = $definition['metric'] ?? null;

            if ($metric === 'count') {
                $counts[self::resolveCountKey($schemaKey, $definition)] = self::buildCountDefinition($schemaKey, $definition);
                continue;
            }

            if ($metric === 'sum' || $metric === 'avg') {
                $compiled                                                    = self::buildAggregateDefinition($schemaKey, $definition);
                $aggregates[$compiled->metric . ':' . $compiled->presentKey] = $compiled;
                continue;
            }

            self::collectQueryMarkers($definition, $filterable, $sortable, $traversable);

            $fields[$schemaKey] = self::buildFieldDefinition($definition);
        }

        return new CompiledSchema(
            $fields,
            $counts,
            $aggregates,
            array_values(array_unique($filterable)),
            array_values(array_unique($sortable)),
            array_values(array_unique($traversable)),
        );
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
     * @return \SineMacula\ApiToolkit\Schema\CompiledCountDefinition
     */
    private static function buildCountDefinition(string $schemaKey, array $definition): CompiledCountDefinition
    {
        $presentKey = self::resolveCountKey($schemaKey, $definition);
        $constraint = $definition['constraint'] ?? null;

        return new CompiledCountDefinition(
            presentKey: $presentKey,
            relation  : is_string($definition['relation'] ?? null) ? $definition['relation'] : $presentKey,
            constraint: $constraint instanceof \Closure ? $constraint : null,
            isDefault : (bool) ($definition['default'] ?? false),
            guards    : $definition['guards'] ?? [],
        );
    }

    /**
     * Resolve the presentation key for an aggregate definition.
     *
     * Returns the bare present key used both as the `presentKey` property on
     * the compiled definition and as the suffix for the Eloquent attribute
     * name. Both the `__sum__:` and `__avg__:` prefixes are 8 characters long,
     * so a single substr offset strips either.
     *
     * The dict key stored in `$aggregates` is `{metric}:{presentKey}`; this
     * method returns only the bare present-key portion.
     *
     * @param  string  $schemaKey
     * @param  array<string, mixed>  $definition
     * @return string
     */
    private static function resolveAggregateKey(string $schemaKey, array $definition): string
    {
        $key = $definition['key'] ?? null;

        if (is_string($key)) {
            return $key;
        }

        return (str_starts_with($schemaKey, '__sum__:') || str_starts_with($schemaKey, '__avg__:'))
            ? substr($schemaKey, 8)
            : $schemaKey;
    }

    /**
     * Build a CompiledAggregateDefinition from a raw definition array.
     *
     * @param  string  $schemaKey
     * @param  array<string, mixed>  $definition
     * @return \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition
     */
    private static function buildAggregateDefinition(string $schemaKey, array $definition): CompiledAggregateDefinition
    {
        $presentKey = self::resolveAggregateKey($schemaKey, $definition);
        $constraint = $definition['constraint'] ?? null;
        $metric     = self::stringOr($definition['metric'] ?? null, '');
        $column     = self::stringOr($definition['column'] ?? null, '');

        return new CompiledAggregateDefinition(
            presentKey: $presentKey,
            relation  : self::stringOr($definition['relation'] ?? null, $presentKey),
            column    : $column,
            metric    : $metric,
            constraint: $constraint instanceof \Closure ? $constraint : null,
            isDefault : (bool) ($definition['default'] ?? false),
            guards    : $definition['guards'] ?? [],
        );
    }

    /**
     * Collect filterable, sortable, and traversable markers from a field
     * definition into the corresponding accumulator arrays.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $filterable
     * @param  array<int, string>  $sortable
     * @param  array<int, string>  $traversable
     * @return void
     */
    private static function collectQueryMarkers(array $definition, array &$filterable, array &$sortable, array &$traversable): void
    {
        $filterableMarker = $definition['filterable'] ?? null;

        if (is_string($filterableMarker)) {
            $filterable[] = $filterableMarker;
        }

        $sortableMarker = $definition['sortable'] ?? null;

        if (is_string($sortableMarker)) {
            $sortable[] = $sortableMarker;
        }

        $traversableMarker = $definition['traversable'] ?? null;

        if (!is_string($traversableMarker)) {
            return;
        }

        $traversable[] = $traversableMarker;
    }

    /**
     * Return $value as a string, or $default when $value is not a string.
     *
     * @param  mixed  $value
     * @param  string  $default
     * @return string
     */
    private static function stringOr(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * Build a CompiledFieldDefinition from a raw definition array.
     *
     * @param  array<string, mixed>  $definition
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private static function buildFieldDefinition(array $definition): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor    : $definition['accessor'] ?? null,
            compute     : $definition['compute']  ?? null,
            relation    : self::resolveFieldRelation($definition),
            resource    : self::resolveFieldResource($definition),
            fields      : $definition['fields'] ?? null,
            constraint  : self::resolveFieldConstraint($definition),
            extras      : (array) ($definition['extras'] ?? []),
            needs       : (array) ($definition['needs'] ?? []),
            guards      : $definition['guards']       ?? [],
            transformers: $definition['transformers'] ?? [],
            openApi     : self::resolveFieldOpenApi($definition),
        );
    }

    /**
     * Resolve the primary relation name for a field definition.
     *
     * The relation may be declared as a string or as a list; only the first
     * entry is used, and only when it is a string.
     *
     * @param  array<string, mixed>  $definition
     * @return string|null
     */
    private static function resolveFieldRelation(array $definition): ?string
    {
        $relations = (array) ($definition['relation'] ?? null);

        return isset($relations[0]) && is_string($relations[0]) ? $relations[0] : null;
    }

    /**
     * Resolve the child resource class for a field definition.
     *
     * @param  array<string, mixed>  $definition
     * @return string|null
     */
    private static function resolveFieldResource(array $definition): ?string
    {
        return isset($definition['resource']) && is_string($definition['resource']) ? $definition['resource'] : null;
    }

    /**
     * Resolve the eager-loading constraint closure for a field definition.
     *
     * @param  array<string, mixed>  $definition
     * @return (\Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): void)|null
     */
    private static function resolveFieldConstraint(array $definition): ?\Closure
    {
        $constraint = $definition['constraint'] ?? null;

        return $constraint instanceof \Closure ? $constraint : null;
    }

    /**
     * Resolve the declared OpenAPI contract for a field definition.
     *
     * @param  array<string, mixed>  $definition
     * @return \SineMacula\ApiToolkit\Schema\OpenApiFieldSchema|null
     */
    private static function resolveFieldOpenApi(array $definition): ?OpenApiFieldSchema
    {
        $openApi = $definition['openapi'] ?? null;

        return $openApi instanceof OpenApiFieldSchema ? $openApi : null;
    }
}
