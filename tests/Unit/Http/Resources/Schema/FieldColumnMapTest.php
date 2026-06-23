<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\FieldColumnMap;

/**
 * Tests for the FieldColumnMap value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FieldColumnMap::class)]
final class FieldColumnMapTest extends TestCase
{
    /**
     * Test that columnsFor returns the declared columns for a mapped field and
     * isMapped returns true.
     *
     * @return void
     */
    public function testColumnsForReturnsDeclaredColumnsWhenMapped(): void
    {
        $map = FieldColumnMap::make(
            ['name' => ['first_name', 'last_name']],
            ['name'],
        );

        self::assertTrue($map->isMapped('name'));
        self::assertSame(['first_name', 'last_name'], $map->columnsFor('name'));
    }

    /**
     * Test that columnsFor returns null for an unmapped field and isMapped
     * returns false.
     *
     * @return void
     */
    public function testColumnsForReturnsNullWhenNotMapped(): void
    {
        $map = FieldColumnMap::make([], []);

        self::assertFalse($map->isMapped('email'));
        self::assertNull($map->columnsFor('email'));
    }

    /**
     * Test that a field present in columns but absent from the mapped set is
     * treated as not mapped.
     *
     * @return void
     */
    public function testIsMappedRequiresExplicitMappedFlag(): void
    {
        $map = FieldColumnMap::make(
            ['name' => ['first_name', 'last_name']],
            [],
        );

        self::assertFalse($map->isMapped('name'));
        self::assertNull($map->columnsFor('name'));
    }

    /**
     * Test that an empty map treats every field as unmapped.
     *
     * @return void
     */
    public function testEmptyMapMapsNothing(): void
    {
        $map = FieldColumnMap::make([], []);

        self::assertFalse($map->isMapped('anything'));
        self::assertNull($map->columnsFor('anything'));
    }
}
