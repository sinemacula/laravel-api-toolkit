<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Base rule for validating callable lists on compiled field definitions.
 *
 * Reports a validation error for every list entry that is not callable, using
 * the callable list and label declared by the concrete subclass. The field-key
 * iteration is inherited from ValidatesEachField.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ValidatesCallableLists extends ValidatesEachField
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
        return $this->collectCallableErrors($resourceClass, $key, $this->getCallables($field));
    }

    /**
     * Return the callable list to validate for the given field definition.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return array<int, callable(mixed, mixed): mixed>
     */
    abstract protected function getCallables(CompiledFieldDefinition $field): array;

    /**
     * Return the human-readable label used in defect messages.
     *
     * @return string
     */
    abstract protected function getLabel(): string;

    /**
     * Collect validation errors for non-callable entries in the given list.
     *
     * @param  string  $resourceClass
     * @param  string  $fieldKey
     * @param  array<int, callable(mixed, mixed): mixed>  $callables
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    protected function collectCallableErrors(string $resourceClass, string $fieldKey, array $callables): array
    {
        $errors = [];

        foreach ($callables as $i => $callable) {

            // @phpstan-ignore function.alreadyNarrowedType
            if (is_callable($callable)) {
                continue;
            }
            // @phpstan-ignore deadCode.unreachable (array<callable> is not enforced at runtime; a non-callable entry reaches here)
            $errors[] = new SchemaValidationError(
                resourceClass: $resourceClass,
                fieldKey: $fieldKey,
                defect: sprintf('%s at index %d is not callable', $this->getLabel(), $i),
            );
        }

        return $errors;
    }
}
