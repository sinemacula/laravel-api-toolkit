<?php

declare(strict_types = 1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\ColumnValueKind;

/**
 * Tests for the ColumnValueKind enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ColumnValueKind::class)]
final class ColumnValueKindTest extends TestCase
{
    /**
     * Provide each catalogue type name with the kind of value it compares.
     *
     * @return iterable<string, array{string, \SineMacula\ApiToolkit\Enums\ColumnValueKind}>
     */
    public static function typeProvider(): iterable
    {
        foreach (['int2', 'int4', 'int8', 'smallint', 'int', 'integer', 'mediumint', 'bigint', 'tinyint'] as $type) {
            yield $type => [$type, ColumnValueKind::INTEGER];
        }

        foreach (['decimal', 'numeric', 'float', 'float8', 'double', 'real', 'float4'] as $type) {
            yield $type => [$type, ColumnValueKind::NUMBER];
        }

        yield 'bool' => ['bool', ColumnValueKind::BOOLEAN];
        yield 'boolean' => ['boolean', ColumnValueKind::BOOLEAN];
        yield 'date' => ['date', ColumnValueKind::DATE];
        yield 'datetime' => ['datetime', ColumnValueKind::DATETIME];
        yield 'timestamp' => ['timestamp', ColumnValueKind::DATETIME];
        yield 'timestamptz' => ['timestamptz', ColumnValueKind::DATETIME];
        yield 'uuid' => ['uuid', ColumnValueKind::UUID];
        yield 'varchar' => ['varchar', ColumnValueKind::TEXT];
        yield 'jsonb' => ['jsonb', ColumnValueKind::TEXT];
    }

    /**
     * Test that each catalogue type name maps to the kind it compares, and any
     * other name to text.
     *
     * @param  string  $type
     * @param  \SineMacula\ApiToolkit\Enums\ColumnValueKind  $kind
     * @return void
     */
    #[DataProvider('typeProvider')]
    public function testTypeNameMapsToTheKindItCompares(string $type, ColumnValueKind $kind): void
    {
        self::assertSame($kind, ColumnValueKind::of($type));
    }

    /**
     * Test that a column with no catalogue type is unchecked.
     *
     * @return void
     */
    public function testAMissingTypeIsUnchecked(): void
    {
        self::assertSame(ColumnValueKind::UNCHECKED, ColumnValueKind::of(null));
    }

    /**
     * Test that each kind is backed by the name its rejection message is keyed
     * by.
     *
     * @return void
     */
    public function testEachKindIsBackedByItsMessageKey(): void
    {
        self::assertSame(
            ['integer', 'number', 'boolean', 'date', 'datetime', 'uuid', 'text', 'unchecked'],
            array_column(ColumnValueKind::cases(), 'value'),
        );
    }
}
