<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition;

/**
 * Tests for the IndexDefinition value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexDefinition::class)]
final class IndexDefinitionTest extends TestCase
{
    /**
     * Test that constructor properties are stored and accessible.
     *
     * @return void
     */
    public function testStoresAllProperties(): void
    {
        $definition = new IndexDefinition(
            name   : 'users_status_name_index',
            columns: ['status', 'name'],
            type   : 'btree',
        );

        self::assertSame('users_status_name_index', $definition->name);
        self::assertSame(['status', 'name'], $definition->columns);
        self::assertSame('btree', $definition->type);
    }

    /**
     * Test that a catalogue entry is read into a definition carrying the
     * columns in the order the connection reported them.
     *
     * @return void
     */
    public function testReadsACatalogueEntry(): void
    {
        $definition = IndexDefinition::fromCatalogueEntry([
            'name'    => 'users_status_name_index',
            'columns' => ['status', 'name'],
            'type'    => 'btree',
        ]);

        self::assertInstanceOf(IndexDefinition::class, $definition);
        self::assertSame('users_status_name_index', $definition->name);
        self::assertSame(['status', 'name'], $definition->columns);
        self::assertSame('btree', $definition->type);
    }

    /**
     * Test that a kind the connection reports in another case is lower-cased,
     * so a comparison against it does not turn on the engine's own folding.
     *
     * @return void
     */
    public function testLowerCasesTheKindTheConnectionReports(): void
    {
        $definition = IndexDefinition::fromCatalogueEntry([
            'name'    => 'users_name_index',
            'columns' => ['name'],
            'type'    => 'BTREE',
        ]);

        self::assertInstanceOf(IndexDefinition::class, $definition);
        self::assertSame('btree', $definition->type);
    }

    /**
     * Test that an entry the connection reports without a kind is read whole
     * and carries a null kind, rather than being passed over: a connection that
     * distinguishes no kinds still reports real indexes.
     *
     * @return void
     */
    public function testReadsAnEntryReportedWithoutAKind(): void
    {
        $definition = IndexDefinition::fromCatalogueEntry([
            'name'    => 'users_name_index',
            'columns' => ['name'],
            'type'    => null,
        ]);

        self::assertInstanceOf(IndexDefinition::class, $definition);
        self::assertNull($definition->type);
        self::assertSame(['name'], $definition->columns);
    }

    /**
     * Test that an entry missing the kind key entirely is read the same way as
     * one reporting it as null.
     *
     * @return void
     */
    public function testReadsAnEntryMissingTheKindKey(): void
    {
        $definition = IndexDefinition::fromCatalogueEntry([
            'name'    => 'users_name_index',
            'columns' => ['name'],
        ]);

        self::assertInstanceOf(IndexDefinition::class, $definition);
        self::assertNull($definition->type);
    }

    /**
     * Test that an entry that is not an array is passed over even when it
     * carries everything a readable one would, so the catalogue is read as the
     * connection reported it rather than through whatever happens to answer an
     * offset.
     *
     * @return void
     */
    public function testPassesOverAnEntryThatIsNotAnArray(): void
    {
        self::assertNull(IndexDefinition::fromCatalogueEntry(new \ArrayObject([
            'name'    => 'users_name_index',
            'columns' => ['name'],
            'type'    => 'btree',
        ])));
    }

    /**
     * Test that an entry reporting a name that is not a name is passed over.
     *
     * @return void
     */
    public function testPassesOverAnEntryWithoutAName(): void
    {
        self::assertNull(IndexDefinition::fromCatalogueEntry([
            'name'    => null,
            'columns' => ['name'],
            'type'    => 'btree',
        ]));
    }

    /**
     * Test that an entry reporting its columns as something other than a list
     * is passed over.
     *
     * @return void
     */
    public function testPassesOverAnEntryWithoutAColumnList(): void
    {
        self::assertNull(IndexDefinition::fromCatalogueEntry([
            'name'    => 'users_name_index',
            'columns' => 'name',
            'type'    => 'btree',
        ]));
    }

    /**
     * Test that an entry carrying a column the connection reports as something
     * other than a name is passed over whole, rather than being read as though
     * the columns after it had moved up. The unreadable entry trails the one a
     * caller would otherwise lead with, so passing over the index and merely
     * skipping the entry give different answers.
     *
     * @return void
     */
    public function testPassesOverAnEntryCarryingAColumnThatIsNotAName(): void
    {
        self::assertNull(IndexDefinition::fromCatalogueEntry([
            'name'    => 'users_name_index',
            'columns' => ['name', 1],
            'type'    => 'btree',
        ]));
    }

    /**
     * Test that an entry reporting a kind that is neither a name nor nothing is
     * passed over, since the kind cannot be compared against.
     *
     * @return void
     */
    public function testPassesOverAnEntryWithAKindThatIsNotAName(): void
    {
        self::assertNull(IndexDefinition::fromCatalogueEntry([
            'name'    => 'users_name_index',
            'columns' => ['name'],
            'type'    => 1,
        ]));
    }

    /**
     * Test that an index leads with its first column and with no other, so a
     * column named second is not read as leading.
     *
     * @return void
     */
    public function testLeadsWithTheFirstColumnAlone(): void
    {
        $definition = new IndexDefinition('users_status_name_index', ['status', 'name'], 'btree');

        self::assertTrue($definition->leadsWith('status'));
        self::assertFalse($definition->leadsWith('name'));
    }

    /**
     * Test that an index covering no columns leads with nothing.
     *
     * @return void
     */
    public function testAnIndexCoveringNoColumnsLeadsWithNothing(): void
    {
        $definition = new IndexDefinition('users_expression_index', [], 'btree');

        self::assertFalse($definition->leadsWith('name'));
    }
}
