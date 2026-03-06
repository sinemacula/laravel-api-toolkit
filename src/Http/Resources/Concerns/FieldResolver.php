<?php

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;

/**
 * Consolidates field state and resolution logic for API resources.
 *
 * Holds the mutable field state (explicit fields, excluded fields, all-fields
 * flag) and resolves which fields should appear in a response based on the
 * compiled schema, API query parameters, and configuration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class FieldResolver
{
    /** @var array<int, string>|null Explicit list of fields to be returned */
    private ?array $fields = null;

    /** @var array<int, string>|null Explicit list of fields to be excluded */
    private ?array $excludedFields = null;

    /** @var bool Whether to return all fields */
    private bool $all = false;

    /**
     * Override the default fields and any requested fields.
     *
     * @param  array<int, string>|null  $fields
     * @return void
     */
    public function withFields(?array $fields): void
    {
        $this->fields = $fields;
    }

    /**
     * Set fields to exclude from the response.
     *
     * @param  array<int, string>|null  $fields
     * @return void
     */
    public function withoutFields(?array $fields): void
    {
        $this->excludedFields = $fields;
    }

    /**
     * Enable all-fields mode.
     *
     * @return void
     */
    public function withAll(): void
    {
        $this->all = true;
    }

    /**
     * Resolve the list of fields that should appear in the response.
     *
     * Merges explicit overrides, API query parameters, default fields, and
     * fixed fields into a deduplicated list. Excluded fields are removed before
     * fixed fields are appended.
     *
     * @param  \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema  $schema
     * @param  string  $resourceType
     * @param  array<int, string>  $defaultFields
     * @param  array<int, string>  $fixedFields
     * @return array<int, string>
     */
    public function getFields(CompiledSchema $schema, string $resourceType, array $defaultFields, array $fixedFields): array
    {
        $this->fields ??= $this->shouldRespondWithAll($resourceType)
            ? $schema->getFieldKeys()
            : (ApiQuery::getFields($resourceType) ?? $defaultFields);

        $resolved = array_diff($this->fields, $this->excludedFields ?? []);

        $configFixed = Config::get('api-toolkit.resources.fixed_fields', []);
        $allFixed    = array_merge($configFixed, $fixedFields);
        $merged      = array_merge($resolved, $allFixed);

        return array_values(array_unique($merged));
    }

    /**
     * Determine whether all fields should be included.
     *
     * Returns true when the all-fields flag has been set explicitly or the
     * API query contains the `:all` token for the given resource type.
     *
     * @param  string  $resourceType
     * @return bool
     */
    public function shouldRespondWithAll(string $resourceType): bool
    {
        return $this->all || in_array(':all', ApiQuery::getFields($resourceType) ?? [], true);
    }

    /**
     * Determine if the counts field should be included.
     *
     * Checks whether the `counts` pseudo-field was explicitly requested,
     * included via all-fields mode, or present in the default fields, while
     * also respecting any exclusion set via `withoutFields()`.
     *
     * @param  string  $resourceType
     * @param  array<int, string>  $defaultFields
     * @return bool
     */
    public function shouldIncludeCountsField(string $resourceType, array $defaultFields): bool
    {
        $requestedFields = ApiQuery::getFields($resourceType);
        $excludedFields  = $this->excludedFields ?? [];

        if (is_array($requestedFields) && in_array('counts', $requestedFields, true)) {
            return !in_array('counts', $excludedFields, true);
        }

        if ($this->shouldRespondWithAll($resourceType) || (is_array($requestedFields) && in_array(':all', $requestedFields, true))) {
            return !in_array('counts', $excludedFields, true);
        }

        return in_array('counts', $defaultFields, true) && !in_array('counts', $excludedFields, true);
    }
}
