<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

/**
 * Immutable description of a single database column's type and nullability.
 *
 * Returned by the schema introspection port to supply the inference tier of the
 * OpenAPI exporter with a column's driver-normalised base type and whether it
 * admits null. Read only by the emission path; never consulted during runtime
 * serialization.
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
     * Build a definition from one catalogue row, or return null when the
     * connection reported anything but a row carrying a name, a type name, and
     * a nullability flag.
     *
     * The row is read as unknown rather than as the shape the schema builder
     * promises, because the same reader rebuilds a definition from its stored
     * form, which another version of the toolkit may have written.
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
     * Return the definition as the catalogue row it is read from, so it can be
     * cached without its class.
     *
     * @return array{name: string, type_name: string, nullable: bool}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type_name' => $this->typeName, 'nullable' => $this->nullable];
    }
}
