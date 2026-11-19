<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation;

use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\AggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\RelationLimit;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;

/**
 * Refuses raw schema definitions the compiled definitions cannot carry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class RawSchemaValidator
{
    /** @var array<string, string> Callable list keys mapped to the label reported for a defect */
    private const array CALLABLE_LISTS = ['guards' => 'Guard', 'caller_guards' => 'Caller guard', 'transformers' => 'Transformer'];

    /** @var list<string> The declarations that put a field on the query surface */
    private const array QUERY_DECLARATIONS = ['filterable', 'sortable', 'searchable', 'traversable'];

    /** @var array<string, string> The keys a rendered resource writes itself, mapped to what each carries */
    private const array RESERVED_KEYS = ['_type' => 'the resource type', RelationLimit::TRUNCATED_KEY => 'the relations a response truncates'];

    /** @var string The key the aggregates payload renders under, reserved where an aggregate is declared */
    private const string AGGREGATES_KEY = 'aggregates';

    /** @var string The characters an aggregate key may hold, so it survives the relation alias and the SQL alias */
    private const string AGGREGATE_KEY_PATTERN = '/^[\w\p{M}]+$/Du';

    /** @var string The form of a pivot column, which the relation qualifies and aliases itself */
    private const string PIVOT_COLUMN_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /** @var int The longest identifier PostgreSQL keeps before truncating it */
    private const int MAX_ATTRIBUTE_BYTES = 63;

    /**
     * Assert that every definition in the raw schema can be compiled.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @param  string  $resourceClass
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public static function assertValid(array $rawSchema, string $resourceClass): void
    {
        $errors       = [];
        $aggregates   = [];
        $relations    = [];
        $traversed    = [];
        $capabilities = [];

        foreach ($rawSchema as $key => $definition) {

            $defects = [
                ...self::collectDefects($definition),
                ...self::collectPivotDefects($definition['pivot'] ?? null),
                ...self::collectGuardDefects($definition),
                ...self::collectCapabilityDefects($key, $definition, $capabilities),
                ...self::collectAggregateDefects($key, $definition, $aggregates),
                ...self::collectRelationDefects($key, $definition, $relations, $traversed),
            ];

            foreach ($defects as $defect) {
                $errors[] = new SchemaValidationError(
                    resourceClass: $resourceClass,
                    fieldKey: $key,
                    defect: $defect,
                );
            }
        }

        $pairs = [
            ...self::collectReservedKeyDefects($rawSchema, $aggregates !== []),
            ...self::collectSharedRelationDefects($relations, $traversed),
        ];

        foreach ($pairs as [$key, $defect]) {
            $errors[] = new SchemaValidationError(
                resourceClass: $resourceClass,
                fieldKey: $key,
                defect: $defect,
            );
        }

        if ($errors !== []) {
            throw new InvalidSchemaException($errors);
        }
    }

    /**
     * Collect the defects in a raw definition's constraint, limit and callable
     * lists.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private static function collectDefects(array $definition): array
    {
        $defects    = [];
        $constraint = $definition['constraint'] ?? null;

        if ($constraint !== null && !$constraint instanceof \Closure) {
            $defects[] = 'Constraint must be a Closure';
        }

        $limit = $definition['limit'] ?? null;

        if ($limit !== null && (!is_int($limit) || $limit < 1)) {
            $defects[] = 'Limit must be an integer of at least 1';
        }

        foreach (self::CALLABLE_LISTS as $list => $label) {
            array_push($defects, ...self::collectCallableDefects($definition[$list] ?? [], $label));
        }

        return $defects;
    }

    /**
     * Collect the defects in a raw definition's pivot column list.
     *
     * @param  mixed  $pivot
     * @return list<string>
     */
    private static function collectPivotDefects(mixed $pivot): array
    {
        if ($pivot === null) {
            return [];
        }

        if (!is_array($pivot) || !array_is_list($pivot)) {
            return ['Pivot must be a list of column names'];
        }

        $defects = [];

        foreach ($pivot as $index => $column) {

            if (is_string($column) && preg_match(self::PIVOT_COLUMN_PATTERN, $column) === 1) {
                continue;
            }

            $defects[] = sprintf('Pivot column at index %d must be a bare column name', $index);
        }

        return $defects;
    }

    /**
     * Collect the query declarations a field's guards forbid.
     *
     * A row guard may read the row, so no query can honour it. A search matches
     * one set of columns for every caller, so a caller guard cannot narrow it.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private static function collectGuardDefects(array $definition): array
    {
        $rowGuarded = self::carries($definition, 'guards');
        $defects    = [];

        foreach (self::QUERY_DECLARATIONS as $declaration) {

            if (!$rowGuarded || !is_string($definition[$declaration] ?? null)) {
                continue;
            }

            $defects[] = sprintf('A field with a row guard may not be %s, as a query would reveal what the guard hides; '
                . 'guard it with visibleTo() where visibility depends on the caller alone', $declaration);
        }

        if (self::carries($definition, 'caller_guards') && is_string($definition['searchable'] ?? null)) {
            $defects[] = 'A field with a caller guard may not be searchable, as a search matches the same columns for every caller';
        }

        return $defects;
    }

    /**
     * Collect the defect of a filterable column another field already declares
     * with a different capability.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $definition
     * @param  array<string, array{string, \SineMacula\ApiToolkit\Enums\Capability}>  $capabilities
     * @return list<string>
     */
    private static function collectCapabilityDefects(string $key, array $definition, array &$capabilities): array
    {
        $column     = $definition['filterable'] ?? null;
        $capability = $definition['capability'] ?? null;

        if (!is_string($column) || !$capability instanceof Capability) {
            return [];
        }

        $declared = $capabilities[$column] ??= [$key, $capability];

        if ($declared[1] === $capability) {
            return [];
        }

        return [sprintf(
            'Field is declared filterable against "%s" with the "%s" capability, and field "%s" declares the same column '
            . 'with "%s", so the column would answer a different set of operators for each',
            $column,
            $capability->value,
            $declared[0],
            $declared[1]->value,
        )];
    }

    /**
     * Collect the defects in a raw aggregate definition.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $definition
     * @param  array<string, string>  $aggregates
     * @return list<string>
     */
    private static function collectAggregateDefects(string $key, array $definition, array &$aggregates): array
    {
        $function = self::aggregateFunction($definition);

        if ($function === null) {
            return str_starts_with($key, AggregateDefinition::KEY_PREFIX)
                ? [sprintf('Aggregate metric must be one of %s', implode(', ', array_column(AggregateFunction::cases(), 'value')))]
                : [];
        }

        $present = SchemaCompiler::resolveAggregateKey($key, $definition);
        $defects = self::collectColumnDefects($function, $definition['column'] ?? null);

        if (preg_match(self::AGGREGATE_KEY_PATTERN, $present) !== 1) {
            $defects[] = sprintf('Aggregate key "%s" may hold only letters, digits and underscores', $present);
        }

        if ((string) (int) $present === $present) {
            $defects[] = sprintf('Aggregate key "%s" reads as an integer, which can turn the aggregates object into a JSON list', $present);
        }

        if (isset($aggregates[$present])) {
            $defects[] = sprintf('Aggregate key "%s" is already declared by "%s"', $present, $aggregates[$present]);
        } else {
            $aggregates[$present] = $key;
        }

        $attribute = CompiledAggregateDefinition::ATTRIBUTE_PREFIX . $present;

        if (strlen($attribute) > self::MAX_ATTRIBUTE_BYTES) {
            $defects[] = sprintf('Aggregate attribute "%s" is longer than %d bytes, which PostgreSQL truncates', $attribute, self::MAX_ATTRIBUTE_BYTES);
        }

        return $defects;
    }

    /**
     * Collect the key and defect of each field that a rendered resource would
     * overwrite.
     *
     * @param  array<string, array<string, mixed>>  $rawSchema
     * @param  bool  $aggregated
     * @return list<array{string, string}>
     */
    private static function collectReservedKeyDefects(array $rawSchema, bool $aggregated): array
    {
        $reserved = self::RESERVED_KEYS;

        if ($aggregated) {
            $reserved[self::AGGREGATES_KEY] = 'the relation aggregates the resource declares';
        }

        $defects = [];

        foreach ($reserved as $key => $purpose) {

            if (!isset($rawSchema[$key]) || self::aggregateFunction($rawSchema[$key]) !== null) {
                continue;
            }

            $defects[] = [$key, sprintf('The key "%s" is reserved for %s', $key, $purpose)];
        }

        return $defects;
    }

    /**
     * Collect the defects in the relations a field definition loads or
     * traverses, grouping it by the relation it loads.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, array<string, mixed>>>  $relations
     * @param  list<string>  $traversed
     * @return list<string>
     */
    private static function collectRelationDefects(string $key, array $definition, array &$relations, array &$traversed): array
    {
        if (self::aggregateFunction($definition) !== null) {
            return [];
        }

        $relation = SchemaCompiler::resolveFieldRelation($definition);
        $marker   = $definition['traversable'] ?? null;

        if ($relation !== null) {
            $relations[$relation][$key] = $definition;
        }

        if (is_string($marker)) {
            $traversed[] = $marker;
        }

        $defects = [];

        foreach (array_unique(array_filter([$relation, $marker], is_string(...))) as $name) {

            if (!str_contains($name, '.')) {
                continue;
            }

            $defects[] = sprintf('Relation "%s" names a nested path; declare each segment as a relation of its own resource', $name);
        }

        return $defects;
    }

    /**
     * Collect the key and defect of each relation whose fields would need rows
     * of their own.
     *
     * The relation loads once and filters never run its constraint, so a
     * constrained relation may be neither traversable nor loaded by another
     * field, and the fields loading it must render one resource.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $relations
     * @param  list<string>  $traversed
     * @return list<array{string, string}>
     */
    private static function collectSharedRelationDefects(array $relations, array $traversed): array
    {
        $defects = [];

        foreach ($relations as $relation => $definitions) {

            $constrained = array_keys(array_filter($definitions, static fn (array $definition): bool => isset($definition['constraint'])));

            if ($constrained !== [] && in_array($relation, $traversed, true)) {
                $defects[] = [
                    $constrained[0],
                    'A traversable relation may not be constrained, as a filter would reach the rows it hides; move the row predicate into a relation method',
                ];
            }

            $loaders   = array_filter($definitions, static fn (array $definition): bool => isset($definition['constraint']) || is_string($definition['resource'] ?? null));
            $resources = array_unique(array_filter(array_column($loaders, 'resource'), is_string(...)));

            if (count($loaders) < 2 || ($constrained === [] && count($resources) < 2)) {
                continue;
            }

            $keys = array_keys($loaders);

            $defects[] = [$keys[0], sprintf(
                'Fields "%s" share the "%s" relation, which loads once, so none may be constrained and all must render one resource; declare a relation method for each',
                implode('", "', $keys),
                $relation,
            )];
        }

        return $defects;
    }

    /**
     * Collect the defects in an aggregate's related column.
     *
     * @param  \SineMacula\ApiToolkit\Enums\AggregateFunction  $function
     * @param  mixed  $column
     * @return list<string>
     */
    private static function collectColumnDefects(AggregateFunction $function, mixed $column): array
    {
        $named = is_string($column) && $column !== '';

        return match (true) {
            $function->requiresColumn()  && !$named          => [sprintf('The %s aggregate must name a related column', $function->value)],
            !$function->requiresColumn() && $column !== null => [sprintf('The %s aggregate takes no column', $function->value)],
            default                                          => [],
        };
    }

    /**
     * Resolve the aggregate function a raw definition declares, if any.
     *
     * @param  array<string, mixed>  $definition
     * @return \SineMacula\ApiToolkit\Enums\AggregateFunction|null
     */
    private static function aggregateFunction(array $definition): ?AggregateFunction
    {
        $metric = $definition['metric'] ?? null;

        return is_string($metric) ? AggregateFunction::tryFrom($metric) : null;
    }

    /**
     * Collect the defects in a single raw guard or transformer list.
     *
     * @param  mixed  $callables
     * @param  string  $label
     * @return list<string>
     */
    private static function collectCallableDefects(mixed $callables, string $label): array
    {
        if (!is_array($callables)) {
            return [sprintf('%ss must be a list of callables', $label)];
        }

        $defects = [];

        foreach (array_values($callables) as $index => $callable) {

            if (is_callable($callable)) {
                continue;
            }

            $defects[] = sprintf('%s at index %d is not callable', $label, $index);
        }

        return $defects;
    }

    /**
     * Determine whether the definition carries a non-empty guard list.
     *
     * @param  array<string, mixed>  $definition
     * @param  string  $list
     * @return bool
     */
    private static function carries(array $definition, string $list): bool
    {
        return is_array($definition[$list] ?? null) && $definition[$list] !== [];
    }
}
