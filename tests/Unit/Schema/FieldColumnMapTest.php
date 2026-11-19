<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

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
     * Test that a declared field is mapped to its declared columns and is not
     * inferred.
     *
     * @return void
     */
    public function testDeclaredFieldMapsToItsDeclaredColumns(): void
    {
        $map = FieldColumnMap::make(['name' => ['first_name', 'last_name']], [], ['name']);

        self::assertTrue($map->isMapped('name'));
        self::assertFalse($map->isInferred('name'));
        self::assertSame(['first_name', 'last_name'], $map->columnsFor('name'));
    }

    /**
     * Test that an inferred field is mapped to its own key.
     *
     * @return void
     */
    public function testInferredFieldMapsToItsOwnKey(): void
    {
        $map = FieldColumnMap::make([], ['email'], ['email']);

        self::assertTrue($map->isMapped('email'));
        self::assertTrue($map->isInferred('email'));
        self::assertSame(['email'], $map->columnsFor('email'));
    }

    /**
     * Test that a declared field with no columns is still mapped.
     *
     * @return void
     */
    public function testDeclaredFieldWithNoColumnsIsMapped(): void
    {
        $map = FieldColumnMap::make(['virtual' => []], [], ['virtual']);

        self::assertTrue($map->isMapped('virtual'));
        self::assertSame([], $map->columnsFor('virtual'));
    }

    /**
     * Test that inferred keys are matched exactly, never as equal numbers.
     *
     * @return void
     */
    public function testInferredKeysAreMatchedExactly(): void
    {
        $map = FieldColumnMap::make([], ['10'], ['10']);

        self::assertTrue($map->isInferred('10'));
        self::assertFalse($map->isInferred('1e1'));
        self::assertFalse($map->isMapped('1e1'));
    }

    /**
     * Test that an empty map maps nothing.
     *
     * @return void
     */
    public function testEmptyMapMapsNothing(): void
    {
        $map = FieldColumnMap::make([], [], []);

        self::assertFalse($map->isMapped('anything'));
        self::assertFalse($map->isInferred('anything'));
        self::assertNull($map->columnsFor('anything'));
        self::assertFalse($map->declares('anything'));
    }

    /**
     * Test that the map declares exactly the names it was built with, mapped or
     * not.
     *
     * @return void
     */
    public function testDeclaresExactlyTheGivenNames(): void
    {
        $map = FieldColumnMap::make([], ['email'], ['email', 'avatar', '10']);

        self::assertTrue($map->declares('email'));
        self::assertTrue($map->declares('avatar'));
        self::assertFalse($map->declares('_type'));
        self::assertFalse($map->declares('Email'));
        self::assertFalse($map->declares('1e1'));
    }
}
