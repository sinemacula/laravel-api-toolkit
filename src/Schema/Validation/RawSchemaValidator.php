<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation;

use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Schema\AggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
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
    private const array CALLABLE_LISTS = ['guards' => 'Guard', 'transformers' => 'Transformer'];

    /** @var string The characters an aggregate key may hold, so it survives the relation alias and the SQL alias */
    private const string AGGREGATE_KEY_PATTERN = '/^[\w\p{M}]+$/Du';

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
        $errors     = [];
        $aggregates = [];
        $relations  = [];

        foreach ($rawSchema as $key => $definition) {

            $defects = [
                ...self::collectDefects($definition),
                ...self::collectAggregateDefects($key, $definition, $aggregates),
                ...self::collectRelationDefects($key, $definition, $relations),
            ];

            foreach ($defects as $defect) {
                $errors[] = new SchemaValidationError(
                    resourceClass: $resourceClass,
                    fieldKey: $key,
                    defect: $defect,
                );
            }
        }

        foreach (self::collectSharedRelationDefects($relations) as $key => $defect) {
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
            array_push($defects, ...self::collectCallableDefects($definition[$list] ?? [], $list, $label));
        }

        return $defects;
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
     * Collect the defects in a field definition's relation, grouping the
     * definitions that shape a relation's rows by the relation they load.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, array<string, mixed>>>  $relations
     * @return list<string>
     */
    private static function collectRelationDefects(string $key, array $definition, array &$relations): array
    {
        $relation = SchemaCompiler::resolveFieldRelation($definition);

        if ($relation === null || self::aggregateFunction($definition) !== null) {
            return [];
        }

        $defects     = [];
        $constrained = isset($definition['constraint']);

        if (str_contains($relation, '.')) {
            $defects[] = sprintf('Relation "%s" names a nested path; declare each segment as a relation of its own resource', $relation);
        }

        if ($constrained && is_string($definition['traversable'] ?? null)) {
            $defects[] = 'A traversable relation may not be constrained, as a filter would reach the rows it hides; move the row predicate into a relation method';
        }

        if ($constrained || is_string($definition['resource'] ?? null)) {
            $relations[$relation][$key] = $definition;
        }

        return $defects;
    }

    /**
     * Collect one defect per relation loaded by several fields that would each
     * need their own rows.
     *
     * The relation loads once, so its fields may neither be constrained nor
     * render different resources.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $relations
     * @return array<string, string>
     */
    private static function collectSharedRelationDefects(array $relations): array
    {
        $defects = [];

        foreach ($relations as $relation => $definitions) {

            $constrained = array_filter($definitions, static fn (array $definition): bool => isset($definition['constraint']));
            $resources   = array_unique(array_filter(array_column($definitions, 'resource'), is_string(...)));

            if (count($definitions) < 2 || ($constrained === [] && count($resources) < 2)) {
                continue;
            }

            $keys = array_keys($definitions);

            $defects[$keys[0]] = sprintf(
                'Fields "%s" share the "%s" relation, which loads once, so none may be constrained and all must render one resource; declare a relation method for each',
                implode('", "', $keys),
                $relation,
            );
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
     * @param  string  $list
     * @param  string  $label
     * @return list<string>
     */
    private static function collectCallableDefects(mixed $callables, string $list, string $label): array
    {
        if (!is_array($callables)) {
            return [sprintf('%s must be a list of callables', ucfirst($list))];
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
}
