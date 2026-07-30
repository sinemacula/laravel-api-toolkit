<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that relation resource class strings reference existing classes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateRelationClasses extends ValidatesEachField
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
        if ($field->resource === null || class_exists($field->resource)) {
            return [];
        }

        return [new SchemaValidationError(
            resourceClass: $resourceClass,
            fieldKey: $key,
            defect: sprintf('Relation resource class "%s" does not exist', $field->resource),
        )];
    }
}
