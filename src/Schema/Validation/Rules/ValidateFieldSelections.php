<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Http\Resources\ResourceTypeIndex;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that default and pinned field selections name declared fields, and
 * that every embedded resource's type can be selected.
 *
 * Neither selection expands :all, so it is a defect in both.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateFieldSelections implements SchemaValidationRule
{
    /**
     * Validate the default fields and each relation's pinned fields.
     *
     * @param  string  $resourceClass
     * @param  string|null  $modelClass
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    #[\Override]
    public function validate(string $resourceClass, ?string $modelClass, CompiledSchema $schema): array
    {
        if (!is_a($resourceClass, ApiResourceInterface::class, true)) {
            return [];
        }

        $errors = [];

        foreach ($this->undeclared($resourceClass::getDefaultFields(), $schema) as $name) {
            $errors[] = new SchemaValidationError($resourceClass, $name, $this->describe('Default', $name, $resourceClass));
        }

        foreach ($schema->getFieldKeys() as $key) {

            $field = $schema->getField($key);

            if ($field === null) {
                continue;
            }

            array_push($errors, ...$this->checkRelation($resourceClass, $key, $field));
        }

        return $errors;
    }

    /**
     * Validate a relation's child resource and the fields it pins.
     *
     * @param  string  $resourceClass
     * @param  string  $key
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    private function checkRelation(string $resourceClass, string $key, CompiledFieldDefinition $field): array
    {
        $child = $field->resource;

        if ($child === null || !is_a($child, ApiResourceInterface::class, true)) {
            return [];
        }

        $errors = [];

        if (!in_array($child, ResourceTypeIndex::classesFor($child::getResourceType()), true)) {
            $errors[] = new SchemaValidationError($resourceClass, $key, sprintf(
                'Relation "%s" renders %s, which is neither mapped nor under resources.paths, so its type cannot be selected',
                $key,
                $child,
            ));
        }

        foreach ($this->undeclared($field->fields ?? [], SchemaCompiler::compile($child)) as $name) {
            $errors[] = new SchemaValidationError($resourceClass, $key, $this->describe('Pinned', $name, $child));
        }

        return $errors;
    }

    /**
     * Describe a selected name the selected resource does not declare.
     *
     * @param  string  $selection
     * @param  string  $name
     * @param  string  $resourceClass
     * @return string
     */
    private function describe(string $selection, string $name, string $resourceClass): string
    {
        return $name === ':all'
            ? sprintf('%s fields cannot use :all; list the fields of %s', $selection, $resourceClass)
            : sprintf('%s field "%s" is not declared on %s', $selection, $name, $resourceClass);
    }

    /**
     * Return the selected names the schema does not declare, :all included.
     *
     * @param  array<int, string>  $names
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<int, string>
     */
    private function undeclared(array $names, CompiledSchema $schema): array
    {
        return array_diff($names, $schema->getSelectableFields());
    }
}
