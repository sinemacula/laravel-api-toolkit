<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that a searchable declaration names a column and a match strategy.
 *
 * A search is emitted as a predicate against the declared column name, so a
 * declaration on a field whose value is produced in the resource rather than
 * read from the table fails at request time with a database error. Two such
 * fields are provable from the schema alone: a computed field, which has no
 * column at all, and a field whose accessor reads a different path from the one
 * it declares. A closure accessor is opaque here and is left to the
 * column-existence checks that consult the database.
 *
 * A column declared with no strategy is reported for a different reason: the
 * compiled plan drops it, so the resource would present a searchable field that
 * quietly matches nothing. Whether the connection carries an index able to
 * serve the declared strategy is a question only a driver can answer, and is
 * checked where the connection is known rather than here.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateSearchableFields extends ValidatesEachField
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
        $column = $field->searchable;
        $defect = $column === null ? null : $this->describeDefect($field, $column);

        if ($defect === null) {
            return [];
        }

        return [new SchemaValidationError(
            resourceClass: $resourceClass,
            fieldKey: $key,
            defect: $defect,
        )];
    }

    /**
     * Describe the defect in a searchable declaration, or return null when the
     * declaration is sound.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  string  $column
     * @return string|null
     */
    private function describeDefect(CompiledFieldDefinition $field, string $column): ?string
    {
        $readsAnotherColumn = is_string($field->accessor) && $field->accessor !== $column;

        return match (true) {
            $field->searchStrategy === null => sprintf('Field is declared searchable against "%s" without a match strategy, so the declaration would be dropped', $column),
            $field->compute !== null        => sprintf('Field is declared searchable but is computed, so there is no "%s" column to search', $column),
            $readsAnotherColumn             => sprintf('Field is declared searchable but is read through an accessor, so there is no "%s" column to search', $column),
            default                         => null,
        };
    }
}
