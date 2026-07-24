<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;

/**
 * Base rule for validations that inspect each compiled field independently.
 *
 * Owns the field-key iteration and the null-field guard so a concrete rule only
 * declares its per-field check via {@see checkField()}, accumulating the errors
 * from every field. Rules whose validation spans more than the field set - e.g.
 * an additional pass over count definitions, or a dependency on the model class
 * - implement SchemaValidationRule directly instead.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ValidatesEachField implements SchemaValidationRule
{
    /**
     * Validate the compiled schema for the given resource class.
     *
     * @param  string  $resourceClass
     * @param  string|null  $modelClass
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
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

            $errors = array_merge($errors, $this->checkField($resourceClass, $key, $field));
        }

        return $errors;
    }

    /**
     * Return the validation errors for a single compiled field.
     *
     * @param  string  $resourceClass
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    abstract protected function checkField(string $resourceClass, string $key, CompiledFieldDefinition $field): array;
}
