<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

/**
 * Immutable description of a single index the connection's catalogue reports.
 *
 * Carries the ordered column list, because the position a column holds in an
 * index decides what the index can serve: only the leading column is a key
 * prefix, so an index naming a column second cannot answer an ordered read of
 * that column alone.
 *
 * The kind is nullable because not every connection reports one. A connection
 * that names no kind has not said the index is unusable, only that it does not
 * distinguish kinds, and a reader deciding what an index can serve has to tell
 * those two apart.
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
     * Build a definition from one catalogue entry, or return null when the
     * connection reported anything but an entry carrying a name, columns that
     * are names, and either a kind or nothing at all.
     *
     * The entry is read as unknown rather than as the shape the schema builder
     * promises, because what the connection actually returned is what decides
     * whether it can be read at all.
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
     * Return the column names an index covers, or null when the connection
     * reported one as something other than a name.
     *
     * An entry that is not a name leaves the position of every column after it
     * unknown, and the leading one is what decides what the index serves, so
     * the whole index is passed over rather than resequenced around the gap.
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
