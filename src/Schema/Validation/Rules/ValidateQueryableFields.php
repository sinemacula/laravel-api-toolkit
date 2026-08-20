<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that filterable and sortable declarations name a backing column.
 *
 * A filter or sort is emitted as a clause against the declared column name, so
 * a declaration on a field whose value is produced in the resource rather than
 * read from the table fails at request time with a database error. Two such
 * fields are provable from the schema alone: a computed field, which has no
 * column at all, and a field whose accessor reads a different path from the one
 * it declares. A closure accessor is opaque here and is left to the
 * column-existence checks that consult the database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateQueryableFields extends ValidatesEachField
{
    /**
     * Return the validation errors for a single compiled field.
     *
     * @param  string  $resourceClass
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    #[\Override]
    protected function checkField(string $resourceClass, string $key, CompiledFieldDefinition $field): array
    {
        $errors = [];

        foreach (['filterable' => $field->filterable, 'sortable' => $field->sortable] as $declaration => $column) {

            if ($column === null || $this->hasBackingColumn($field, $column)) {
                continue;
            }

            $errors[] = new SchemaValidationError(
                resourceClass: $resourceClass,
                fieldKey: $key,
                defect: $this->describeDefect($field, $declaration, $column),
            );
        }

        return $errors;
    }

    /**
     * Determine whether the declared column can back the field's value.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  string  $column
     * @return bool
     */
    private function hasBackingColumn(CompiledFieldDefinition $field, string $column): bool
    {
        if ($field->compute !== null) {
            return false;
        }

        return !is_string($field->accessor) || $field->accessor === $column;
    }

    /**
     * Build the defect message for a declaration with no backing column.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  string  $declaration
     * @param  string  $column
     * @return string
     */
    private function describeDefect(CompiledFieldDefinition $field, string $declaration, string $column): string
    {
        $source = $field->compute !== null ? 'computed' : 'read through an accessor';

        return sprintf('Field is declared %s but is %s, so there is no "%s" column to query', $declaration, $source, $column);
    }
}
