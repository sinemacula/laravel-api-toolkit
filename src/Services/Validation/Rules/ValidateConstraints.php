<?php

namespace SineMacula\ApiToolkit\Services\Validation\Rules;

use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\SchemaValidationError;

/**
 * Validate that constraint values are Closures.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateConstraints implements SchemaValidationRule
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

            if ($field === null || $field->constraint === null) {
                continue;
            }

            if (!($field->constraint instanceof \Closure)) {
                $errors[] = new SchemaValidationError(
                    resourceClass: $resourceClass,
                    fieldKey: $key,
                    defect: 'Constraint must be a Closure',
                );
            }
        }

        foreach ($schema->getCountDefinitions() as $count) {

            if ($count->constraint === null) {
                continue;
            }

            if (!($count->constraint instanceof \Closure)) {
                $errors[] = new SchemaValidationError(
                    resourceClass: $resourceClass,
                    fieldKey: $count->presentKey,
                    defect: 'Constraint must be a Closure',
                );
            }
        }

        return $errors;
    }
}
