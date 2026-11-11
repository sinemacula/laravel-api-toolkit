<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;

/**
 * Tests for the ColumnDefinition value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnDefinition::class)]
final class ColumnDefinitionTest extends TestCase
{
    /**
     * Test that constructor properties are stored and accessible.
     *
     * @return void
     */
    public function testStoresAllProperties(): void
    {
        $definition = new ColumnDefinition(
            name    : 'email',
            typeName: 'varchar',
            nullable: true,
        );

        self::assertSame('email', $definition->name);
        self::assertSame('varchar', $definition->typeName);
        self::assertTrue($definition->nullable);
    }

    /**
     * Test that a non-null column reports a false nullable flag.
     *
     * @return void
     */
    public function testStoresNonNullableColumn(): void
    {
        $definition = new ColumnDefinition(
            name    : 'id',
            typeName: 'bigint',
            nullable: false,
        );

        self::assertFalse($definition->nullable);
    }

    /**
     * Test that a catalogue row is read with its type name lower-cased.
     *
     * @return void
     */
    public function testReadsACatalogueRow(): void
    {
        $definition = ColumnDefinition::fromCatalogueEntry(['name' => 'email', 'type_name' => 'VARCHAR', 'nullable' => true]);

        self::assertEquals(new ColumnDefinition('email', 'varchar', true), $definition);
    }

    /**
     * Provide rows a definition cannot be read from.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function unreadableRowProvider(): iterable
    {
        yield 'not an array' => ['email'];
        yield 'an object answering every offset' => [new \ArrayObject(['name' => 'email', 'type_name' => 'varchar', 'nullable' => true])];
        yield 'no name' => [['type_name' => 'varchar', 'nullable' => true]];
        yield 'a name that is not a string' => [['name' => 1, 'type_name' => 'varchar', 'nullable' => true]];
        yield 'no type name' => [['name' => 'email', 'nullable' => true]];
        yield 'a type name that is not a string' => [['name' => 'email', 'type_name' => 1, 'nullable' => true]];
        yield 'no nullability flag' => [['name' => 'email', 'type_name' => 'varchar']];
        yield 'a nullability flag that is not a boolean' => [['name' => 'email', 'type_name' => 'varchar', 'nullable' => 1]];
    }

    /**
     * Test that a row lacking a name, type name or nullability is passed over.
     *
     * @param  mixed  $entry
     * @return void
     */
    #[DataProvider('unreadableRowProvider')]
    public function testPassesOverAnUnreadableRow(mixed $entry): void
    {
        self::assertNull(ColumnDefinition::fromCatalogueEntry($entry));
    }

    /**
     * Test that a definition round-trips through its catalogue row.
     *
     * @return void
     */
    public function testRoundTripsThroughItsCatalogueRow(): void
    {
        $definition = new ColumnDefinition('email', 'varchar', true);

        self::assertSame(['name' => 'email', 'type_name' => 'varchar', 'nullable' => true], $definition->toArray());
        self::assertEquals($definition, ColumnDefinition::fromCatalogueEntry($definition->toArray()));
    }
}
