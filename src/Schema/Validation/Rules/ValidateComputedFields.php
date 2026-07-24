<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that computed field values are callable or reference valid methods
 * on the resource class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateComputedFields extends ValidatesEachField
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
        $compute = $field->compute;

        $valid = $compute === null
            || $compute instanceof \Closure
            || is_callable($compute)
            || (is_string($compute) && method_exists($resourceClass, $compute));

        if ($valid) {
            return [];
        }

        return [new SchemaValidationError(
            resourceClass: $resourceClass,
            fieldKey: $key,
            defect: 'Computed field value is not callable and does not reference an existing method on the resource class',
        )];
    }
}
