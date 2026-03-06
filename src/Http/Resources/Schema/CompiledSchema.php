<?php

namespace SineMacula\ApiToolkit\Http\Resources\Schema;

/**
 * Typed value object holding compiled field and count definitions.
 *
 * Provides typed access to the compiled schema data, replacing the untyped
 * associative arrays previously used in the schema cache.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CompiledSchema
{
    /**
     * Create a new compiled schema.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition>  $fields
     * @param  array<string, \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition>  $counts
     */
    public function __construct(

        /** The compiled field definitions keyed by field name */
        private array $fields,

        /** The compiled count definitions keyed by present key */
        private array $counts,

    ) {}

    /**
     * Return the compiled field definition for the given key, or null if not
     * present.
     *
     * @param  string  $key
     * @return \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition|null
     */
    public function getField(string $key): ?CompiledFieldDefinition
    {
        return $this->fields[$key] ?? null;
    }

    /**
     * Return all field keys, excluding count definitions.
     *
     * @return array<int, string>
     */
    public function getFieldKeys(): array
    {
        return array_keys($this->fields);
    }

    /**
     * Return the full associative array of count definitions keyed by present
     * key.
     *
     * @return array<string, \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition>
     */
    public function getCountDefinitions(): array
    {
        return $this->counts;
    }

    /**
     * Determine whether a field key exists in the compiled fields.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasField(string $key): bool
    {
        return isset($this->fields[$key]);
    }
}
