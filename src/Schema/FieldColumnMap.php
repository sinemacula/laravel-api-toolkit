<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Per-resource-type map of field key to declared column reads.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class FieldColumnMap
{
    /**
     * Create a new field-column map.
     *
     * @param  array<string, array<int, string>>  $columns
     * @param  array<int, string>  $mapped
     */
    private function __construct(

        /** Field key to declared column reads for provably-mapped fields */
        private array $columns,

        /** The set of field keys that are provably column-mapped */
        private array $mapped,
    ) {}

    /**
     * Build a map from per-field column lists and the provably-mapped keys.
     *
     * @param  array<string, array<int, string>>  $columns
     * @param  array<int, string>  $mapped
     * @return self
     */
    public static function make(array $columns, array $mapped): self
    {
        return new self($columns, array_values($mapped));
    }

    /**
     * Return the declared columns for a field, or null when it is not mapped.
     *
     * @param  string  $field
     * @return array<int, string>|null
     */
    public function columnsFor(string $field): ?array
    {
        return $this->isMapped($field) ? ($this->columns[$field] ?? []) : null;
    }

    /**
     * Determine whether the given field is provably column-mapped.
     *
     * @param  string  $field
     * @return bool
     */
    public function isMapped(string $field): bool
    {
        return in_array($field, $this->mapped, true);
    }
}
