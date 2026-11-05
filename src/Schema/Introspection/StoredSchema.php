<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

/**
 * Converts schema definitions to and from the form the metadata cache holds.
 *
 * The cache holds arrays and scalars only, so a definition is stored as the
 * catalogue entry it is read from and rebuilt through the same reader. A stored
 * value that cannot be rebuilt whole is rejected rather than read in part, so
 * the caller treats it as a miss and reads the schema again.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StoredSchema
{
    /**
     * Return the column definitions in the form the cache holds.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>  $definitions
     * @return array<int, array{name: string, type_name: string, nullable: bool}>
     */
    public static function fromColumnDefinitions(array $definitions): array
    {
        return array_map(static fn (ColumnDefinition $definition): array => $definition->toArray(), array_values($definitions));
    }

    /**
     * Rebuild the column definitions, keyed by column name, from their stored
     * form, or return null when the stored value cannot be read.
     *
     * @param  mixed  $stored
     * @return array<string, \SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition>|null
     */
    public static function toColumnDefinitions(mixed $stored): ?array
    {
        $definitions = self::rebuild($stored, ColumnDefinition::fromCatalogueEntry(...));

        if ($definitions === null) {
            return null;
        }

        $keyed = [];

        foreach ($definitions as $definition) {
            $keyed[$definition->name] = $definition;
        }

        return $keyed;
    }

    /**
     * Return the index definitions in the form the cache holds.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>  $indexes
     * @return array<int, array{name: string, columns: array<int, string>, type: string|null}>
     */
    public static function fromIndexes(array $indexes): array
    {
        return array_map(static fn (IndexDefinition $index): array => $index->toArray(), array_values($indexes));
    }

    /**
     * Rebuild the index definitions from their stored form, or return null when
     * the stored value cannot be read.
     *
     * A connection may report an index without a kind, but a stored entry
     * always carries one, null included, so an entry without it is unreadable
     * rather than an index of no known kind.
     *
     * @param  mixed  $stored
     * @return array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null
     */
    public static function toIndexes(mixed $stored): ?array
    {
        return self::rebuild($stored, static function (mixed $entry): ?IndexDefinition {

            if (!is_array($entry) || !array_key_exists('type', $entry)) {
                return null;
            }

            return IndexDefinition::fromCatalogueEntry($entry);
        });
    }

    /**
     * Rebuild every stored entry with the given reader, or return null when the
     * stored value is not an array or any entry in it cannot be read.
     *
     * One unreadable entry rejects the whole value rather than being passed
     * over, since an index list read short would report an index missing that
     * the table carries.
     *
     * @template TDefinition of object
     *
     * @param  mixed  $stored
     * @param  callable(mixed): (TDefinition|null)  $read
     * @return array<int, TDefinition>|null
     */
    private static function rebuild(mixed $stored, callable $read): ?array
    {
        if (!is_array($stored)) {
            return null;
        }

        $definitions = [];

        foreach ($stored as $entry) {

            $definition = $read($entry);

            if ($definition === null) {
                return null;
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }
}
