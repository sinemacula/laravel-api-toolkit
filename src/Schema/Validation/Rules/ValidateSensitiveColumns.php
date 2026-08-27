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
    /** @var array<int, string> The shipped column names, mirrored by the published config file and used as the fallback whenever that file does not declare its own list */
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
     * A published config predating the list, or declaring something other than
     * a list, falls back to the shipped names rather than leaving the rule
     * inert: the package config is merged one key deep, so an application that
     * publishes its own resources block replaces this key wholesale. Entries
     * that are not strings never match a column name, since the comparison is
     * strict.
     *
     * @return array<mixed>
     */
    private function sensitiveColumns(): array
    {
        $configured = Config::get('api-toolkit.resources.sensitive_columns', self::DEFAULTS);

        return is_array($configured) ? $configured : self::DEFAULTS;
    }
}
