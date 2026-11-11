<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

/**
 * Immutable description of a single index the connection's catalogue reports.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class IndexDefinition
{
    /**
     * Create a new index definition.
     *
     * @param  string  $name
     * @param  array<int, string>  $columns
     * @param  string|null  $type
     */
    public function __construct(

        /** The index name as the connection reports it */
        public string $name,

        /** The indexed columns, in key order */
        public array $columns,

        /** The lower-cased index kind, or null when the connection names none */
        public ?string $type,
    ) {}

    /**
     * Build a definition from a catalogue entry, or null when it is malformed.
     *
     * Cached entries may have been written by another toolkit version.
     *
     * @param  mixed  $entry
     * @return self|null
     */
    public static function fromCatalogueEntry(mixed $entry): ?self
    {
        if (!is_array($entry)) {
            return null;
        }

        $name    = $entry['name']    ?? null;
        $type    = $entry['type']    ?? null;
        $columns = $entry['columns'] ?? null;
        $names   = is_array($columns) ? self::columnNames($columns) : null;

        if (!is_string($name) || $names === null || !($type === null || is_string($type))) {
            return null;
        }

        return new self($name, $names, $type === null ? null : strtolower($type));
    }

    /**
     * Return the definition as a cacheable catalogue entry.
     *
     * @return array{name: string, columns: array<int, string>, type: string|null}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'columns' => $this->columns, 'type' => $this->type];
    }

    /**
     * Determine whether the index leads with the given column.
     *
     * @param  string  $column
     * @return bool
     */
    public function leadsWith(string $column): bool
    {
        return ($this->columns[0] ?? null) === $column;
    }

    /**
     * Return the column names an index covers, or null when any is not a name.
     *
     * @param  array<mixed>  $columns
     * @return array<int, string>|null
     */
    private static function columnNames(array $columns): ?array
    {
        $names = [];

        foreach ($columns as $column) {

            if (!is_string($column)) {
                return null;
            }

            $names[] = $column;
        }

        return $names;
    }
}
