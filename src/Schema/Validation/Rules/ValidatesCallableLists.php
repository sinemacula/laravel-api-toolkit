<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use SineMacula\ApiToolkit\Enums\CallableList;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Base rule for validating callable lists on compiled schema definitions.
 *
 * Reports a validation error for every list entry that is not callable, using
 * the callable list declared by the concrete subclass. Fields are checked first
 * through the iteration inherited from ValidatesEachField, then count
 * definitions, then aggregate definitions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ValidatesCallableLists extends ValidatesEachField
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
        $errors = parent::validate($resourceClass, $modelClass, $schema);
        $list   = $this->getCallableList();

        foreach ($schema->getCountDefinitions() as $count) {
            $errors = array_merge($errors, $this->collectCallableErrors($resourceClass, $count->presentKey, $list->select($count->guards, $count->transformers)));
        }

        foreach ($schema->getAggregateDefinitions() as $aggregate) {
            $errors = array_merge($errors, $this->collectCallableErrors($resourceClass, $aggregate->presentKey, $list->select($aggregate->guards, $aggregate->transformers)));
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
    #[\Override]
    protected function checkField(string $resourceClass, string $key, CompiledFieldDefinition $field): array
    {
        return $this->collectCallableErrors($resourceClass, $key, $this->getCallableList()->select($field->guards, $field->transformers));
    }

    /**
     * Return the callable list this rule validates.
     *
     * @return \SineMacula\ApiToolkit\Enums\CallableList
     */
    abstract protected function getCallableList(): CallableList;

    /**
     * Collect validation errors for non-callable entries in the given list.
     *
     * @param  string  $resourceClass
     * @param  string  $fieldKey
     * @param  array<int, callable(mixed, mixed): mixed>  $callables
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    private function collectCallableErrors(string $resourceClass, string $fieldKey, array $callables): array
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
                defect: sprintf('%s at index %d is not callable', $this->getCallableList()->label(), $i),
            );
        }

        return $errors;
    }
}
