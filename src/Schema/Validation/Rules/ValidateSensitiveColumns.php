<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that no field declares a sensitive column filterable or sortable.
 *
 * The declared surface is the only gate on what a client may filter or sort by,
 * so a credential or verification column reaching it becomes an oracle: the
 * caller never reads the value but narrows on it one comparison at a time. The
 * configured columns are refused where the resource declares them, so the
 * defect is a schema failure rather than a silently exploitable endpoint.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateSensitiveColumns extends ValidatesEachField
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
        $sensitive = $this->sensitiveColumns();
        $errors    = [];

        foreach (['filterable' => $field->filterable, 'sortable' => $field->sortable] as $declaration => $column) {

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

    /**
     * Read the configured sensitive column names.
     *
     * @return array<int, string>
     */
    private function sensitiveColumns(): array
    {
        $configured = Config::get('api-toolkit.resources.sensitive_columns', []);

        return is_array($configured)
            ? array_values(array_filter($configured, 'is_string'))
            : [];
    }
}
