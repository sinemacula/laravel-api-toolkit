<?php

namespace SineMacula\ApiToolkit\Services\Validation\Rules;

use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\SchemaValidationError;

/**
 * Validate that all guard entries in the compiled schema are callable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateGuards implements SchemaValidationRule
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

            foreach ($field->guards as $i => $guard) {

                if (!is_callable($guard)) {
                    $errors[] = new SchemaValidationError(
                        resourceClass: $resourceClass,
                        fieldKey: $key,
                        defect: sprintf('Guard at index %d is not callable', $i),
                    );
                }
            }
        }

        foreach ($schema->getCountDefinitions() as $count) {

            foreach ($count->guards as $i => $guard) {

                if (!is_callable($guard)) {
                    $errors[] = new SchemaValidationError(
                        resourceClass: $resourceClass,
                        fieldKey: $count->presentKey,
                        defect: sprintf('Guard at index %d is not callable', $i),
                    );
                }
            }
        }

        return $errors;
    }
}
