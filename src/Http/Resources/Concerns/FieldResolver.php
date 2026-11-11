<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Schema\CompiledSchema;

/**
 * Holds a resource's field selection and resolves its response fields.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @managed-static
 */
final class FieldResolver
{
    /** @var array<string, array<int, string>> Assembled field lists, keyed by a fingerprint of their inputs */
    private static array $resolvedCache = [];

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
     * Excluded fields are removed before fixed fields are appended.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
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

        $excluded = $this->excludedFields ?? [];

        /** @var array<int, string> $configFixed */
        $configFixed = Config::get('api-toolkit.resources.fixed_fields', []);

        // Request-scoped memo so a homogeneous page assembles the list once
        $key = self::cacheKey($this->fields, $excluded, $configFixed, $fixedFields);

        return self::$resolvedCache[$key] ??= array_values(array_unique(
            array_merge(array_diff($this->fields, $excluded), $configFixed, $fixedFields),
        ));
    }

    /**
     * Clear the static assembled-field memo.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$resolvedCache = [];
    }

    /**
     * Determine whether all fields should be included.
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
     * @param  string  $resourceType
     * @param  array<int, string>  $defaultFields
     * @return bool
     */
    public function shouldIncludeCountsField(string $resourceType, array $defaultFields): bool
    {
        return $this->shouldIncludeVirtualField('counts', $resourceType, $defaultFields);
    }

    /**
     * Determine if the sums field should be included.
     *
     * @param  string  $resourceType
     * @param  array<int, string>  $defaultFields
     * @return bool
     */
    public function shouldIncludeSumsField(string $resourceType, array $defaultFields): bool
    {
        return $this->shouldIncludeVirtualField('sums', $resourceType, $defaultFields);
    }

    /**
     * Determine if the averages field should be included.
     *
     * @param  string  $resourceType
     * @param  array<int, string>  $defaultFields
     * @return bool
     */
    public function shouldIncludeAveragesField(string $resourceType, array $defaultFields): bool
    {
        return $this->shouldIncludeVirtualField('averages', $resourceType, $defaultFields);
    }

    /**
     * Build the memo key fingerprinting the field-assembly inputs.
     *
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $excluded
     * @param  array<int, string>  $configFixed
     * @param  array<int, string>  $fixedFields
     * @return string
     */
    private static function cacheKey(array $fields, array $excluded, array $configFixed, array $fixedFields): string
    {
        return implode('|', [
            implode("\0", $fields),
            implode("\0", $excluded),
            implode("\0", $configFixed),
            implode("\0", $fixedFields),
        ]);
    }

    /**
     * Determine if a virtual metric field should be included.
     *
     * @param  string  $fieldName
     * @param  string  $resourceType
     * @param  array<int, string>  $defaultFields
     * @return bool
     */
    private function shouldIncludeVirtualField(string $fieldName, string $resourceType, array $defaultFields): bool
    {
        $requestedFields = ApiQuery::getFields($resourceType);
        $excludedFields  = $this->excludedFields ?? [];

        if (is_array($requestedFields) && in_array($fieldName, $requestedFields, true)) {
            return !in_array($fieldName, $excludedFields, true);
        }

        if ($this->shouldRespondWithAll($resourceType) || (is_array($requestedFields) && in_array(':all', $requestedFields, true))) {
            return !in_array($fieldName, $excludedFields, true);
        }

        return in_array($fieldName, $defaultFields, true) && !in_array($fieldName, $excludedFields, true);
    }
}
