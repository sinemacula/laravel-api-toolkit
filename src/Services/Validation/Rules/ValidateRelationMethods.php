<?php

namespace SineMacula\ApiToolkit\Services\Validation\Rules;

use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\SchemaValidationError;

/**
 * Validate that relation names exist as methods on the associated model.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateRelationMethods implements SchemaValidationRule
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
        if ($modelClass === null) {
            return [];
        }

        $errors = [];

        foreach ($schema->getFieldKeys() as $key) {

            $field = $schema->getField($key);

            if ($field === null || $field->relation === null) {
                continue;
            }

            $error = $this->validateRelationMethod($resourceClass, $key, $modelClass, $field->relation);

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        foreach ($schema->getCountDefinitions() as $count) {

            $error = $this->validateRelationMethod($resourceClass, $count->presentKey, $modelClass, $count->relation);

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * Validate that a relation method exists and has a return type hinting
     * to a Relation subclass.
     *
     * @param  string  $resourceClass
     * @param  string  $fieldKey
     * @param  string  $modelClass
     * @param  string  $relationMethod
     * @return \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError|null
     */
    private function validateRelationMethod(string $resourceClass, string $fieldKey, string $modelClass, string $relationMethod): ?SchemaValidationError
    {
        if (!method_exists($modelClass, $relationMethod)) {
            return new SchemaValidationError(
                resourceClass: $resourceClass,
                fieldKey: $fieldKey,
                defect: sprintf('Relation method "%s" does not exist on model "%s"', $relationMethod, $modelClass),
            );
        }

        $returnType = (new ReflectionMethod($modelClass, $relationMethod))->getReturnType();

        if ($this->isRelationReturnType($returnType)) {
            return null;
        }

        return new SchemaValidationError(
            resourceClass: $resourceClass,
            fieldKey: $fieldKey,
            defect: $this->describeReturnTypeDefect($returnType, $relationMethod, $modelClass),
        );
    }

    /**
     * Determine whether the given reflection type is a Relation subclass.
     *
     * @param  \ReflectionType|null  $returnType
     * @return bool
     */
    private function isRelationReturnType(?ReflectionType $returnType): bool
    {
        if ($returnType instanceof ReflectionNamedType) {
            return is_subclass_of($returnType->getName(), Relation::class);
        }

        if ($returnType instanceof ReflectionUnionType) {
            foreach ($returnType->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType && is_subclass_of($member->getName(), Relation::class)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build a human-readable defect message for a non-Relation return type.
     *
     * @param  \ReflectionType|null  $returnType
     * @param  string  $relationMethod
     * @param  string  $modelClass
     * @return string
     */
    private function describeReturnTypeDefect(?ReflectionType $returnType, string $relationMethod, string $modelClass): string
    {
        if ($returnType === null) {
            return sprintf('Relation method "%s" on model "%s" has no return type hint', $relationMethod, $modelClass);
        }

        if ($returnType instanceof ReflectionUnionType) {
            return sprintf(
                'Relation method "%s" on model "%s" has a union return type with no Relation subclass member',
                $relationMethod,
                $modelClass,
            );
        }

        return sprintf(
            'Relation method "%s" on model "%s" has return type "%s" which is not a Relation subclass',
            $relationMethod,
            $modelClass,
            (string) $returnType,
        );
    }
}
