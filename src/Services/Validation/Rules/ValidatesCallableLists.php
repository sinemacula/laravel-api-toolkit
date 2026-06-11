<?php

namespace SineMacula\ApiToolkit\Services\Validation\Rules;

use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\SchemaValidationError;

/**
 * Base rule for validating callable lists on compiled field definitions.
 *
 * Iterates the compiled fields and reports a validation error for every list
 * entry that is not callable, using the callable list and label declared by
 * the concrete subclass.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ValidatesCallableLists implements SchemaValidationRule
{
    /**
     * Validate the compiled schema for the given resource class.
     *
     * @param  string  $resourceClass
     * @param  string|null  $modelClass
     * @param  \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema  $schema
     * @return array<int, \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError>
     */
    #[\Override]
    public function validate(string $resourceClass, ?string $modelClass, CompiledSchema $schema): array
    {
        $errors = [];

        foreach ($schema->getFieldKeys() as $key) {

            $field = $schema->getField($key);

            if ($field === null) {
                continue;
            }

            $errors = array_merge($errors, $this->collectCallableErrors($resourceClass, $key, $this->getCallables($field)));
        }

        return $errors;
    }

    /**
     * Return the callable list to validate for the given field definition.
     *
     * @param  \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition  $field
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
     * @return array<int, \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError>
     */
    protected function collectCallableErrors(string $resourceClass, string $fieldKey, array $callables): array
    {
        $errors = [];

        foreach ($callables as $i => $callable) {

            if (!is_callable($callable)) { // @phpstan-ignore function.alreadyNarrowedType
                $errors[] = new SchemaValidationError(
                    resourceClass: $resourceClass,
                    fieldKey: $fieldKey,
                    defect: sprintf('%s at index %d is not callable', $this->getLabel(), $i),
                );
            }
        }

        return $errors;
    }
}
