<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that a searchable declaration names a column and a match strategy.
 *
 * Index backing needs the connection, so it is checked elsewhere.
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
     * Describe the searchable declaration's defect, or null when it is sound.
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
