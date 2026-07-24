<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that a declared accessor path is not an empty string.
 *
 * A null accessor (none declared) and a callable accessor are both valid; only
 * an empty-string path is a defect.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateAccessors extends ValidatesEachField
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
        if ($field->accessor !== '') {
            return [];
        }

        return [new SchemaValidationError(
            resourceClass: $resourceClass,
            fieldKey: $key,
            defect: 'Accessor path must not be empty',
        )];
    }
}
