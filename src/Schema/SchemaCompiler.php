<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\Validation\RawSchemaValidator;

/**
 * Compiles raw resource schema arrays into typed CompiledSchema objects.
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

        RawSchemaValidator::assertValid($rawSchema, $resourceClass);

        return self::$cache[$resourceClass] = self::buildCompiledSchema($rawSchema);
    }

    /**
     * Clear all cached compiled schemas.
     *
     * Compiled schemas live for the worker, so a lifecycle flush leaves them;
     * this is for test isolation.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Resolve the presentation key for an aggregate definition.
     *
     * @param  string  $schemaKey
     * @param  array<string, mixed>  $definition
     * @return string
     */
    public static function resolveAggregateKey(string $schemaKey, array $definition): string
    {
        $key = $definition['key'] ?? null;

        if (is_string($key)) {
            return $key;
        }

        return str_starts_with($schemaKey, AggregateDefinition::KEY_PREFIX)
            ? substr($schemaKey, strlen(AggregateDefinition::KEY_PREFIX))
            : $schemaKey;
    }

    /**
     * Resolve the primary relation name for a field definition.
     *
     * @param  array<string, mixed>  $definition
     * @return string|null
     */
    public static function resolveFieldRelation(array $definition): ?string
    {
        $relations = (array) ($definition['relation'] ?? null);

        return isset($relations[0]) && is_string($relations[0]) ? $relations[0] : null;
    }

    /**
     * Build a CompiledSchema from a raw schema array.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private static function buildCompiledSchema(array $rawSchema): CompiledSchema
    {
        /** @var array<string, \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition> $fields */
        $fields = [];

        /** @var array<string, \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition> $aggregates */
        $aggregates = [];

        $filterable  = [];
        $sortable    = [];
        $traversable = [];
        $searchable  = [];
        $resources   = [];
        $declarers   = [];

        foreach ($rawSchema as $schemaKey => $definition) {

            $function = self::resolveAggregateFunction($definition);

            if ($function !== null) {
                $compiled                          = self::buildAggregateDefinition($schemaKey, $definition, $function);
                $aggregates[$compiled->presentKey] = $compiled;
                continue;
            }

            self::collectQueryMarkers($definition, $filterable, $sortable, $traversable, $searchable);
            self::collectDeclarers($schemaKey, $definition, $declarers);

            $field = self::buildFieldDefinition($definition);

            if ($field->relation !== null && $field->resource !== null) {
                $resources[$field->relation] = $field->resource;
            }

            $fields[$schemaKey] = $field;
        }

        $traversable = array_values(array_unique($traversable));

        return new CompiledSchema(
            $fields,
            $aggregates,
            $filterable,
            array_values(array_unique($sortable)),
            $traversable,
            $searchable,
            array_intersect_key($resources, array_flip($traversable)),
            self::conditionalKeys($declarers['filter'] ?? []),
            self::conditionalKeys($declarers['sort'] ?? []),
            self::conditionalKeys($declarers['traversal'] ?? []),
        );
    }

    /**
     * Record whether each field declaring a filter, sort or relation key is
     * caller-guarded.
     *
     * @param  string  $schemaKey
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, array<string, bool>>>  $declarers
     * @return void
     */
    private static function collectDeclarers(string $schemaKey, array $definition, array &$declarers): void
    {
        $guarded = self::callableList($definition, 'caller_guards') !== [];

        $keys = [
            'filter'    => self::resolveCapability($definition) === null ? null : ($definition['filterable'] ?? null),
            'sort'      => $definition['sortable']    ?? null,
            'traversal' => $definition['traversable'] ?? null,
        ];

        foreach ($keys as $kind => $key) {

            if (!is_string($key)) {
                continue;
            }

            $declarers[$kind][$key][$schemaKey] = $guarded;
        }
    }

    /**
     * Return the keys only caller-guarded fields declare, mapped to those
     * fields.
     *
     * @param  array<string, array<string, bool>>  $declarers
     * @return array<string, list<string>>
     */
    private static function conditionalKeys(array $declarers): array
    {
        $conditional = array_filter($declarers, static fn (array $guarded): bool => array_filter($guarded) === $guarded);

        return array_map(array_keys(...), $conditional);
    }

    /**
     * Resolve the aggregate function a raw definition declares, if any.
     *
     * @param  array<string, mixed>  $definition
     * @return \SineMacula\ApiToolkit\Enums\AggregateFunction|null
     */
    private static function resolveAggregateFunction(array $definition): ?AggregateFunction
    {
        $metric = $definition['metric'] ?? null;

        return is_string($metric) ? AggregateFunction::tryFrom($metric) : null;
    }

    /**
     * Build a CompiledAggregateDefinition from a raw definition array.
     *
     * @param  string  $schemaKey
     * @param  array<string, mixed>  $definition
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @return \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition
     */
    private static function buildAggregateDefinition(string $schemaKey, array $definition, AggregateFunction $function): CompiledAggregateDefinition
    {
        $presentKey = self::resolveAggregateKey($schemaKey, $definition);
        $constraint = $definition['constraint'] ?? null;
        $column     = $definition['column']     ?? null;

        return new CompiledAggregateDefinition(
            presentKey  : $presentKey,
            relation    : self::stringOr($definition['relation'] ?? null, $presentKey),
            function    : $function,
            column      : is_string($column) ? $column : null,
            constraint  : $constraint instanceof \Closure ? $constraint : null,
            isDefault   : (bool) ($definition['default'] ?? false),
            guards      : self::callableList($definition, 'guards'),
            transformers: self::callableList($definition, 'transformers'),
            callerGuards: self::callableList($definition, 'caller_guards'),
        );
    }

    /**
     * Collect a field definition's query markers into the accumulators.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, \SineMacula\ApiToolkit\Enums\Capability>  $filterable
     * @param  array<int, string>  $sortable
     * @param  array<int, string>  $traversable
     * @param  array<string, \SineMacula\ApiToolkit\Enums\SearchStrategy>  $searchable
     * @return void
     */
    private static function collectQueryMarkers(array $definition, array &$filterable, array &$sortable, array &$traversable, array &$searchable): void
    {
        $filterableMarker = $definition['filterable'] ?? null;
        $capability       = self::resolveCapability($definition);

        if (is_string($filterableMarker) && $capability !== null) {
            $filterable[$filterableMarker] = $capability;
        }

        $sortableMarker = $definition['sortable'] ?? null;

        if (is_string($sortableMarker)) {
            $sortable[] = $sortableMarker;
        }

        $traversableMarker = $definition['traversable'] ?? null;

        if (is_string($traversableMarker)) {
            $traversable[] = $traversableMarker;
        }

        $searchableMarker = $definition['searchable'] ?? null;
        $strategy         = self::resolveSearchStrategy($definition);

        if (!is_string($searchableMarker) || $strategy === null) {
            return;
        }

        $searchable[$searchableMarker] = $strategy;
    }

    /**
     * Resolve the declared capability for a filterable field definition.
     *
     * @param  array<string, mixed>  $definition
     * @return \SineMacula\ApiToolkit\Enums\Capability|null
     */
    private static function resolveCapability(array $definition): ?Capability
    {
        $capability = $definition['capability'] ?? null;

        return $capability instanceof Capability ? $capability : null;
    }

    /**
     * Resolve the declared match strategy for a searchable field definition.
     *
     * @param  array<string, mixed>  $definition
     * @return \SineMacula\ApiToolkit\Enums\SearchStrategy|null
     */
    private static function resolveSearchStrategy(array $definition): ?SearchStrategy
    {
        $strategy = $definition['strategy'] ?? null;

        return $strategy instanceof SearchStrategy ? $strategy : null;
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
     * Return the string entries of the given list, reindexed.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function stringList(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }

    /**
     * Return the callable entries of a guard or transformer list, reindexed.
     *
     * @param  array<string, mixed>  $definition
     * @param  'caller_guards'|'guards'|'transformers'  $list
     * @return ($list is 'guards' ? list<callable(mixed, mixed): bool> : ($list is 'caller_guards' ? list<callable(mixed): bool> : list<callable(mixed, mixed): mixed>))
     */
    private static function callableList(array $definition, string $list): array
    {
        $callables = $definition[$list] ?? null;

        return is_array($callables) ? array_values(array_filter($callables, is_callable(...))) : [];
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
            accessor        : $definition['accessor'] ?? null,
            compute         : $definition['compute']  ?? null,
            relation        : self::resolveFieldRelation($definition),
            resource        : self::resolveFieldResource($definition),
            fields          : is_array($definition['fields'] ?? null) ? self::stringList($definition['fields']) : null,
            constraint      : self::resolveFieldConstraint($definition),
            extras          : self::stringList((array) ($definition['extras'] ?? [])),
            needs           : self::stringList((array) ($definition['needs'] ?? [])),
            guards          : self::callableList($definition, 'guards'),
            transformers    : self::callableList($definition, 'transformers'),
            openApi         : self::resolveFieldOpenApi($definition),
            filterable      : self::declaredColumn($definition, 'filterable'),
            filterCapability: self::resolveCapability($definition),
            sortable        : self::declaredColumn($definition, 'sortable'),
            indexedBy       : self::declaredColumn($definition, 'indexed'),
            unindexedReason : self::declaredColumn($definition, 'unindexed'),
            searchable      : self::declaredColumn($definition, 'searchable'),
            searchStrategy  : self::resolveSearchStrategy($definition),
            limit           : is_int($definition['limit'] ?? null) ? $definition['limit'] : null,
            callerGuards    : self::callableList($definition, 'caller_guards'),
        );
    }

    /**
     * Return the column declared at the given key, or null when not a string.
     *
     * @param  array<string, mixed>  $definition
     * @param  string  $key
     * @return string|null
     */
    private static function declaredColumn(array $definition, string $key): ?string
    {
        $value = $definition[$key] ?? null;

        return is_string($value) ? $value : null;
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
     * @return (\Closure(\Illuminate\Contracts\Database\Eloquent\Builder): void)|null
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
