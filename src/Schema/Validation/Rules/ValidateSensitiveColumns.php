<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that no field declares a sensitive column queryable.
 *
 * A queryable secret is an oracle even when its value is never returned.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateSensitiveColumns extends ValidatesEachField
{
    /** @var array<int, string> Shipped defaults, mirrored by the published config */
    public const array DEFAULTS = [
        'password',
        'token',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'email_verified_at',
    ];

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

    /**
     * Read the configured sensitive column names.
     *
     * Falls back to the defaults when the published config declares no list.
     *
     * @return array<mixed>
     */
    private function sensitiveColumns(): array
    {
        $configured = Config::get('api-toolkit.resources.sensitive_columns', self::DEFAULTS);

        return is_array($configured) ? $configured : self::DEFAULTS;
    }
}
