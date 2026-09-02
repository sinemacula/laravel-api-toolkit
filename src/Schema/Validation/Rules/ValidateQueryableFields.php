<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that filterable and sortable declarations name a backing column.
 *
 * A filter or sort is emitted as a clause against the declared column name, so
 * a declaration with nothing behind that name fails at request time with a
 * database error. The declaration is proved from whichever source can answer
 * it. Two defects are provable from the schema alone: a computed field, which
 * has no column at all, and a field whose accessor reads a different path from
 * the one it declares. The rest is proved against the table, which is the only
 * authority on whether a column exists - a closure accessor reads a path this
 * rule cannot resolve, and a scalar field names a column nothing in the
 * resource contradicts.
 *
 * A column listing that comes back empty is the connection saying nothing
 * rather than saying the table is bare, since no table carries no columns, so a
 * boot with no database behind it, or one whose migrations have not run, proves
 * nothing and the rule stays silent for it. A listing that was read and does
 * not name the column has proved the declaration wrong. The listing is read
 * during validation and cached from there, so proving a declaration costs the
 * request path nothing.
 *
 * The comparison against the listing is exact. The emitted clause quotes the
 * column as it was declared, and an engine treating a quoted identifier as
 * case-sensitive resolves nothing else, so a spelling the table does not carry
 * is a defect rather than a difference.
 *
 * The capability a filterable column is declared with is governed by the same
 * check, since the capability is what makes the column filterable in the first
 * place: there is no way to declare one on a field without declaring the other.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ValidateQueryableFields implements SchemaValidationRule
{
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
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    #[\Override]
    public function validate(string $resourceClass, ?string $modelClass, CompiledSchema $schema): array
    {
        $declared = $this->declaredFields($schema);

        if ($declared === []) {
            return [];
        }

        $model   = $this->resolveModel($modelClass);
        $columns = $model === null ? [] : $this->introspector->getColumns($model);
        $table   = $model?->getTable() ?? '';
        $errors  = [];

        foreach ($declared as $key => $field) {

            foreach ($this->defects($field, $columns, $table) as $defect) {
                $errors[] = new SchemaValidationError(
                    resourceClass: $resourceClass,
                    fieldKey: $key,
                    defect: $defect,
                );
            }
        }

        return $errors;
    }

    /**
     * Return the fields carrying a filterable or sortable declaration, keyed by
     * field key.
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
     * Resolve the model behind the resource, or null where the mapped class is
     * not an Eloquent model and has no table to read.
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
     * Return the defects a single field's declarations carry.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  array<int, string>  $columns
     * @param  string  $table
     * @return array<int, string>
     */
    private function defects(CompiledFieldDefinition $field, array $columns, string $table): array
    {
        $defects = [];

        foreach (['filterable' => $field->filterable, 'sortable' => $field->sortable] as $declaration => $column) {

            if ($column === null) {
                continue;
            }

            $defect = $this->describeDefect($field, $declaration, $column, $columns, $table);

            if ($defect === null) {
                continue;
            }

            $defects[] = $defect;
        }

        return $defects;
    }

    /**
     * Describe what a declaration is missing, or return null where a column
     * backs it.
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
        $source = $this->resourceSource($field, $column);

        if ($source !== null) {
            return sprintf('Field is declared %s but is %s, so there is no "%s" column to query', $declaration, $source, $column);
        }

        // An empty listing is the connection saying nothing, not saying bare.
        if ($columns === [] || in_array($column, $columns, true)) {
            return null;
        }

        return sprintf('Field is declared %s against "%s", and table "%s" carries no such column', $declaration, $column, $table);
    }

    /**
     * Name how the resource produces the field's value where it produces it
     * rather than reading the declared column, or return null where the schema
     * alone cannot say the column is missing.
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
