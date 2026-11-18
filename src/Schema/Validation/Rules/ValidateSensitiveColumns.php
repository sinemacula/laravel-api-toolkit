<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use SineMacula\ApiToolkit\Config\ResourceSettings;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that no field declares a sensitive column queryable or aggregated.
 *
 * A queryable secret is an oracle even when its value is never returned, and a
 * minimum or maximum returns a real row's value.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateSensitiveColumns extends ValidatesEachField
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
        $errors    = parent::validate($resourceClass, $modelClass, $schema);
        $sensitive = ResourceSettings::sensitiveColumns();

        foreach ($schema->getAggregateDefinitions() as $key => $aggregate) {

            if ($aggregate->column === null || !in_array($aggregate->column, $sensitive, true)) {
                continue;
            }

            $errors[] = new SchemaValidationError(
                resourceClass: $resourceClass,
                fieldKey: $key,
                defect: sprintf('Aggregate is declared over "%s", which is configured as a sensitive column and may never be aggregated', $aggregate->column),
            );
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
        $sensitive = ResourceSettings::sensitiveColumns();
        $errors    = [];

        foreach (['filterable' => $field->filterable, 'sortable' => $field->sortable, 'searchable' => $field->searchable] as $declaration => $column) {

            if ($column === null || !in_array($column, $sensitive, true)) {
                continue;
            }

            $errors[] = new SchemaValidationError(
                resourceClass: $resourceClass,
                fieldKey: $key,
                defect: sprintf('Field is declared %s against "%s", which is configured as a sensitive column and may never be queried', $declaration, $column),
            );
        }

        return $errors;
    }
}
