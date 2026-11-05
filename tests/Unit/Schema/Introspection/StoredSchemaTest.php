<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\StoredSchema;

/**
 * Tests for the conversion of schema definitions to and from the form the
 * metadata cache holds.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(StoredSchema::class)]
final class StoredSchemaTest extends TestCase
{
    /**
     * Test that column definitions are stored as a list of catalogue rows.
     *
     * @return void
     */
    public function testStoresColumnDefinitionsAsAListOfRows(): void
    {
        $stored = StoredSchema::fromColumnDefinitions([
            'id'  => new ColumnDefinition('id', 'integer', false),
            'bio' => new ColumnDefinition('bio', 'text', true),
        ]);

        self::assertSame([
            ['name' => 'id', 'type_name' => 'integer', 'nullable' => false],
            ['name' => 'bio', 'type_name' => 'text', 'nullable' => true],
        ], $stored);
    }

    /**
     * Test that stored rows are rebuilt into column definitions keyed by column
     * name.
     *
     * @return void
     */
    public function testRebuildsColumnDefinitionsKeyedByName(): void
    {
        $definitions = StoredSchema::toColumnDefinitions([
            ['name' => 'id', 'type_name' => 'integer', 'nullable' => false],
            ['name' => 'bio', 'type_name' => 'text', 'nullable' => true],
        ]);

        self::assertEquals([
            'id'  => new ColumnDefinition('id', 'integer', false),
            'bio' => new ColumnDefinition('bio', 'text', true),
        ], $definitions);
    }

    /**
     * Test that an empty stored list rebuilds as an empty set rather than as a
     * value that cannot be read.
     *
     * @return void
     */
    public function testRebuildsAnEmptyListAsAnEmptySet(): void
    {
        self::assertSame([], StoredSchema::toColumnDefinitions([]));
        self::assertSame([], StoredSchema::toIndexes([]));
    }

    /**
     * Provide stored column definitions that cannot be rebuilt whole.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function unreadableColumnDefinitionsProvider(): iterable
    {
        yield 'not an array' => ['stale'];
        yield 'an incomplete object' => [[unserialize(serialize(new ColumnDefinition('id', 'integer', false)), ['allowed_classes' => false])]];
        yield 'an unreadable row after a readable one' => [[['name' => 'id', 'type_name' => 'integer', 'nullable' => false], ['name' => 'bio']]];
    }

    /**
     * Test that stored column definitions that cannot be rebuilt whole are
     * rejected.
     *
     * @param  mixed  $stored
     * @return void
     */
    #[DataProvider('unreadableColumnDefinitionsProvider')]
    public function testRejectsUnreadableColumnDefinitions(mixed $stored): void
    {
        self::assertNull(StoredSchema::toColumnDefinitions($stored));
    }

    /**
     * Test that index definitions are stored as a list of catalogue entries.
     *
     * @return void
     */
    public function testStoresIndexesAsAListOfEntries(): void
    {
        $stored = StoredSchema::fromIndexes([
            2 => new IndexDefinition('users_name_index', ['name'], 'btree'),
            5 => new IndexDefinition('users_body_fulltext', ['body'], null),
        ]);

        self::assertSame([
            ['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree'],
            ['name' => 'users_body_fulltext', 'columns' => ['body'], 'type' => null],
        ], $stored);
    }

    /**
     * Test that stored entries are rebuilt into index definitions in order.
     *
     * @return void
     */
    public function testRebuildsIndexes(): void
    {
        $indexes = StoredSchema::toIndexes([
            ['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree'],
            ['name' => 'users_body_fulltext', 'columns' => ['body'], 'type' => null],
        ]);

        self::assertEquals([
            new IndexDefinition('users_name_index', ['name'], 'btree'),
            new IndexDefinition('users_body_fulltext', ['body'], null),
        ], $indexes);
    }

    /**
     * Provide stored index catalogues that cannot be rebuilt whole.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function unreadableIndexesProvider(): iterable
    {
        yield 'not an array' => ['stale'];
        yield 'an incomplete object' => [[unserialize(serialize(new IndexDefinition('users_name_index', ['name'], 'btree')), ['allowed_classes' => false])]];
        yield 'an entry without its kind' => [[['name' => 'users_name_index', 'columns' => ['name']]]];
        yield 'an unreadable entry after a readable one' => [[['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree'], ['name' => 'users_bio_index']]];
    }

    /**
     * Test that a stored index catalogue that cannot be rebuilt whole is
     * rejected rather than read short.
     *
     * @param  mixed  $stored
     * @return void
     */
    #[DataProvider('unreadableIndexesProvider')]
    public function testRejectsUnreadableIndexes(mixed $stored): void
    {
        self::assertNull(StoredSchema::toIndexes($stored));
    }
}
