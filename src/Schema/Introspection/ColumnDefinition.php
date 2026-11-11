<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

/**
 * Immutable description of a single database column's type and nullability.
 *
 * Feeds the OpenAPI exporter's type inference; never read at runtime.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ColumnDefinition
{
    /**
     * Create a new column definition.
     *
     * @param  string  $name
     * @param  string  $typeName
     * @param  bool  $nullable
     */
    public function __construct(

        /** The column name */
        public string $name,

        /** The driver-normalised base type, e.g. 'varchar', 'bigint', 'json' */
        public string $typeName,

        /** Whether the column admits null */
        public bool $nullable,
    ) {}

    /**
     * Build a definition from a catalogue row, or null when it is malformed.
     *
     * Cached rows may have been written by another toolkit version.
     *
     * @param  mixed  $entry
     * @return self|null
     */
    public static function fromCatalogueEntry(mixed $entry): ?self
    {
        if (!is_array($entry)) {
            return null;
        }

        $name     = $entry['name']      ?? null;
        $typeName = $entry['type_name'] ?? null;
        $nullable = $entry['nullable']  ?? null;

        if (!is_string($name) || !is_string($typeName) || !is_bool($nullable)) {
            return null;
        }

        return new self($name, strtolower($typeName), $nullable);
    }

    /**
     * Return the definition as a cacheable catalogue row.
     *
     * @return array{name: string, type_name: string, nullable: bool}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type_name' => $this->typeName, 'nullable' => $this->nullable];
    }
}
