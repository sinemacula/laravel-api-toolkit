<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Validation\Rules;

use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;

/**
 * Validate that all guard entries in the compiled schema are callable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateGuards extends ValidatesCallableLists
{
    /**
     * Validate the compiled schema for the given resource class.
     *
     * @param  string  $resourceClass
     * @param  string|null  $modelClass
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<int, \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError>
     */
    #[\Override]
    public function validate(string $resourceClass, ?string $modelClass, CompiledSchema $schema): array
    {
        $errors = parent::validate($resourceClass, $modelClass, $schema);

        foreach ($schema->getCountDefinitions() as $count) {
            $errors = array_merge($errors, $this->collectCallableErrors($resourceClass, $count->presentKey, $count->guards));
        }

        return $errors;
    }

    /**
     * Return the callable list to validate for the given field definition.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return array<int, callable(mixed, mixed): bool>
     */
    #[\Override]
    protected function getCallables(CompiledFieldDefinition $field): array
    {
        return $field->guards;
    }

    /**
     * Return the human-readable label used in defect messages.
     *
     * @return string
     */
    #[\Override]
    protected function getLabel(): string
    {
        return 'Guard';
    }
}
