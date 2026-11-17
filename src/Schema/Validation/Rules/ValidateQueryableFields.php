<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration;

/**
 * Validate that filterable and sortable declarations name a backing column.
 *
 * Column matching is exact, since quoted identifiers may be case-sensitive. An
 * empty listing proves nothing, so a declaration it would decide is reported as
 * unverified.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ValidateQueryableFields implements SchemaValidationRule
{
    /** @var string The declaration a capability belongs to */
    private const string FILTERABLE = 'filterable';

    /** @var string The declaration an order key belongs to */
    private const string SORTABLE = 'sortable';

    /** @var string The defect of a sortable column the relevance key shadows */
    private const string RESERVED_DEFECT = 'Field is declared %s against "%s", which the order parameter reserves for search relevance, '
        . 'so the column cannot be ordered by';

    /**
     * Create a new queryable field validation rule.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     * @return void
     */
    public function __construct(

        /** Reads the column listing behind the model's table */
        private SchemaIntrospectionProvider $introspector,
    ) {}

    /**
     * Validate the compiled schema for the given resource class.
     *
     * @param  string  $resourceClass
     * @param  string|null  $modelClass
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError|\SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>
     */
    #[\Override]
    public function validate(string $resourceClass, ?string $modelClass, CompiledSchema $schema): array
    {
        $declared = $this->declaredFields($schema);

        if ($declared === []) {
            return [];
        }

        $model   = $this->resolveModel($modelClass);
        $columns = $model === null ? null : $this->introspector->getColumns($model);
        $table   = $model?->getTable() ?? '';
        $errors  = [];

        foreach ($declared as $key => $field) {

            [$defects, $unproven] = $this->defects($field, $columns, $table);

            foreach ($defects as $defect) {
                $errors[] = new SchemaValidationError(
                    resourceClass: $resourceClass,
                    fieldKey: $key,
                    defect: $defect,
                );
            }

            if ($model === null) {
                continue;
            }

            foreach ($unproven as $check) {
                $errors[] = new UnverifiedDeclaration(
                    resourceClass: $resourceClass,
                    fieldKey: $key,
                    check: $check,
                    modelClass: $model::class,
                );
            }
        }

        foreach ($this->capabilityConflicts($declared) as $key => $defect) {
            $errors[] = new SchemaValidationError(
                resourceClass: $resourceClass,
                fieldKey: $key,
                defect: $defect,
            );
        }

        return $errors;
    }

    /**
     * Return the capability conflicts between fields sharing a column, by key.
     *
     * An alias repeating the same capability is not a conflict.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition>  $declared
     * @return array<string, string>
     */
    private function capabilityConflicts(array $declared): array
    {
        $governing = [];
        $conflicts = [];

        foreach ($declared as $key => $field) {

            if ($field->filterable === null || $field->filterCapability === null) {
                continue;
            }

            $seen = $governing[$field->filterable] ?? null;

            if ($seen === null) {
                $governing[$field->filterable] = [$key, $field->filterCapability];

                continue;
            }

            if ($seen[1] === $field->filterCapability) {
                continue;
            }

            $conflicts[$key] = sprintf(
                'Field is declared filterable against "%s" with the "%s" capability, and field "%s" declares the same '
                . 'column with "%s", so the operators the column answers are left to declaration order',
                $field->filterable,
                $field->filterCapability->value,
                $seen[0],
                $seen[1]->value,
            );
        }

        return $conflicts;
    }

    /**
     * Return the fields declaring a filterable or sortable column, by key.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<string, \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition>
     */
    private function declaredFields(CompiledSchema $schema): array
    {
        $declared = [];

        foreach ($schema->getFieldKeys() as $key) {

            $field = $schema->getField($key);

            if ($field === null || ($field->filterable === null && $field->sortable === null)) {
                continue;
            }

            $declared[$key] = $field;
        }

        return $declared;
    }

    /**
     * Resolve the model behind the resource, or null when it is not a model.
     *
     * @param  string|null  $modelClass
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    private function resolveModel(?string $modelClass): ?Model
    {
        if ($modelClass === null || !is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Model */
        return new $modelClass;
    }

    /**
     * Return the defects a field's declarations carry, then the claims an
     * unread column listing leaves unproven.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  array<int, string>|null  $columns
     * @param  string  $table
     * @return array{array<int, string>, array<int, string>}
     */
    private function defects(CompiledFieldDefinition $field, ?array $columns, string $table): array
    {
        $defects  = [];
        $unproven = [];

        foreach ([self::FILTERABLE => $field->filterable, self::SORTABLE => $field->sortable] as $declaration => $column) {

            if ($column === null) {
                continue;
            }

            $defect = $this->describeDefect($field, $declaration, $column, $columns ?? [], $table);

            if ($defect !== null) {
                $defects[] = $defect;
            } elseif ($columns === []) {
                $unproven[] = sprintf('table "%s" carries the "%s" column the %s declaration names', $table, $column, $declaration);
            }
        }

        return [$defects, $unproven];
    }

    /**
     * Describe what a declaration is missing, or null when a column backs it.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  string  $declaration
     * @param  string  $column
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @return string|null
     */
    private function describeDefect(CompiledFieldDefinition $field, string $declaration, string $column, array $columns, string $table): ?string
    {
        $dropped  = $declaration === self::FILTERABLE && $field->filterCapability === null;
        $source   = $this->resourceSource($field, $column);
        $reserved = $declaration === self::SORTABLE && $column === OrderApplier::ORDER_BY_RELEVANCE;

        // An empty listing means the columns are unknown
        $unbacked = $columns !== [] && !in_array($column, $columns, true);

        return match (true) {
            $dropped         => sprintf('Field is declared filterable against "%s" without a capability, so the declaration would be dropped', $column),
            $source !== null => sprintf('Field is declared %s but is %s, so there is no "%s" column to query', $declaration, $source, $column),
            $reserved        => sprintf(self::RESERVED_DEFECT, $declaration, $column),
            $unbacked        => sprintf('Field is declared %s against "%s", and table "%s" carries no such column', $declaration, $column, $table),
            default          => null,
        };
    }

    /**
     * Name how the resource derives the value, or null when it may be a column.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  string  $column
     * @return string|null
     */
    private function resourceSource(CompiledFieldDefinition $field, string $column): ?string
    {
        $readsAnotherColumn = is_string($field->accessor) && $field->accessor !== $column;

        return match (true) {
            $field->compute !== null => 'computed',
            $readsAnotherColumn      => 'read through an accessor',
            default                  => null,
        };
    }
}
