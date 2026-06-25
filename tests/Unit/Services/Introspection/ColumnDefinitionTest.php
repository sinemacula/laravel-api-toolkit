<?php

declare(strict_types = 1);

namespace Tests\Unit\Services\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Introspection\ColumnDefinition;

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
}
