<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Per-resource-type map of field key to declared or inferred column reads.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class FieldColumnMap
{
    /**
     * Create a new field-column map.
     *
     * @param  array<string, array<int, string>>  $declared
     * @param  array<int, string>  $inferred
     */
    private function __construct(

        /** Field key to the columns its needs() declaration reads */
        private array $declared,

        /** Plain field keys whose column is taken to be the key itself */
        private array $inferred,
    ) {}

    /**
     * Build a map from the declared column reads and the inferred plain keys.
     *
     * @param  array<string, array<int, string>>  $declared
     * @param  array<int, string>  $inferred
     * @return self
     */
    public static function make(array $declared, array $inferred): self
    {
        return new self($declared, $inferred);
    }

    /**
     * Return the columns a field reads, or null when it is not mapped.
     *
     * @param  string  $field
     * @return array<int, string>|null
     */
    public function columnsFor(string $field): ?array
    {
        return $this->declared[$field] ?? ($this->isInferred($field) ? [$field] : null);
    }

    /**
     * Determine whether the given field is declared or inferred.
     *
     * @param  string  $field
     * @return bool
     */
    public function isMapped(string $field): bool
    {
        return array_key_exists($field, $this->declared) || $this->isInferred($field);
    }

    /**
     * Determine whether the field's column is inferred from its own key.
     *
     * @param  string  $field
     * @return bool
     */
    public function isInferred(string $field): bool
    {
        return in_array($field, $this->inferred, true);
    }
}
