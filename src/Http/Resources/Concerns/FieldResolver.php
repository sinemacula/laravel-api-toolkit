<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use SineMacula\ApiToolkit\Facades\ApiQuery;

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
     * @param  class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>  $resourceClass
     * @return array<int, string>
     */
    public function getFields(string $resourceClass): array
    {
        $fields      = self::effectiveFields($resourceClass, $this->fields, $this->all);
        $excluded    = $this->excludedFields ?? [];
        $fixedFields = $resourceClass::getFixedFields();

        // Request-scoped memo so a homogeneous page assembles the list once
        $key = self::cacheKey($fields, $excluded, $fixedFields);

        return self::$resolvedCache[$key] ??= array_values(array_unique(
            array_merge(array_diff($fields, $excluded), $fixedFields),
        ));
    }

    /**
     * Resolve the fields a resource selects before exclusions and fixed fields.
     *
     * Pinned fields win, then all fields with the aggregates payload, then the
     * requested fields, then the defaults. Planning and rendering share it.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>  $resourceClass
     * @param  array<int, string>|null  $pinned
     * @param  bool  $all
     * @return array<int, string>
     */
    public static function effectiveFields(string $resourceClass, ?array $pinned, bool $all): array
    {
        if ($pinned !== null) {
            return $pinned;
        }

        $requested = ApiQuery::getFields($resourceClass::getResourceType());

        if ($all || in_array(':all', $requested ?? [], true)) {
            return [...$resourceClass::getAllFields(), 'aggregates'];
        }

        return $requested ?? $resourceClass::getDefaultFields();
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
     * Build the memo key fingerprinting the field-assembly inputs.
     *
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $excluded
     * @param  array<int, string>  $fixedFields
     * @return string
     */
    private static function cacheKey(array $fields, array $excluded, array $fixedFields): string
    {
        return implode('|', [
            implode("\0", $fields),
            implode("\0", $excluded),
            implode("\0", $fixedFields),
        ]);
    }
}
