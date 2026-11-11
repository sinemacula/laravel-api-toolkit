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
     * Test that an entry is read with its columns in the reported order.
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
     * Test that a kind reported in another case is lower-cased.
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
     * Test that an entry reported without a kind is read with a null kind.
     *
     * A connection that distinguishes no kinds still reports real indexes.
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
     * Test that an entry missing the kind key reads the same as a null kind.
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
     * Test that a non-array entry is passed over even when it holds every key.
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
     * Test that an entry whose columns are not a list is passed over.
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
     * Test that an entry with a non-string column is passed over whole.
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
     * Test that an entry with a kind neither string nor null is passed over.
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
     * Test that an index leads with its first column only.
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

    /**
     * Test that a definition round-trips through its catalogue entry.
     *
     * @return void
     */
    public function testRoundTripsThroughItsCatalogueEntry(): void
    {
        $definition = new IndexDefinition('users_status_name_index', ['status', 'name'], 'btree');

        self::assertSame(['name' => 'users_status_name_index', 'columns' => ['status', 'name'], 'type' => 'btree'], $definition->toArray());
        self::assertEquals($definition, IndexDefinition::fromCatalogueEntry($definition->toArray()));

        $unkinded = new IndexDefinition('users_body_index', ['body'], null);

        self::assertEquals($unkinded, IndexDefinition::fromCatalogueEntry($unkinded->toArray()));
    }
}
