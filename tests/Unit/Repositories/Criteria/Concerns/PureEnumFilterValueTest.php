<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\PureEnumFilterValue;
use Tests\Fixtures\Models\Device;
use Tests\TestCase;

/**
 * Tests for translating a pure-enum filter value from wire form to storage.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PureEnumFilterValue::class)]
final class PureEnumFilterValueTest extends TestCase
{
    /**
     * Test that a wire name resolves to the case name the column stores.
     *
     * @return void
     */
    public function testWireNameResolvesToTheStoredCaseName(): void
    {
        self::assertSame('MOBILE_APP', PureEnumFilterValue::resolve(new Device, 'platform', 'mobile_app'));
    }

    /**
     * Test that each wire name in a list resolves to its case name, in order.
     *
     * @return void
     */
    public function testListResolvesEachWireName(): void
    {
        self::assertSame(['WEB', 'MOBILE_APP'], PureEnumFilterValue::resolve(new Device, 'platform', ['web', 'mobile_app']));
    }

    /**
     * Provide values that name no case in wire form.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function unmatchedValueProvider(): iterable
    {
        yield 'declared name' => ['MOBILE_APP'];
        yield 'mixed case' => ['Web'];
        yield 'unknown' => ['console'];
        yield 'integer' => [1];
        yield 'boolean' => [true];
    }

    /**
     * Test that a value naming no case resolves to one no case name equals.
     *
     * @param  mixed  $value
     * @return void
     */
    #[DataProvider('unmatchedValueProvider')]
    public function testValueNamingNoCaseResolvesToAnUnmatchableName(mixed $value): void
    {
        self::assertSame('', PureEnumFilterValue::resolve(new Device, 'platform', $value));
    }

    /**
     * Test that a list makes every item naming no case unmatchable.
     *
     * @return void
     */
    public function testListMakesOnlyTheUnmatchedItemsUnmatchable(): void
    {
        self::assertSame(['DESKTOP', '', '', ''], PureEnumFilterValue::resolve(new Device, 'platform', ['desktop', 'DESKTOP', 'console', ['web']]));
    }

    /**
     * Test that a null keeps its meaning, alone or in a list.
     *
     * @return void
     */
    public function testNullIsKept(): void
    {
        self::assertNull(PureEnumFilterValue::resolve(new Device, 'platform', null));
        self::assertSame([null, 'WEB'], PureEnumFilterValue::resolve(new Device, 'platform', [null, 'web']));
    }

    /**
     * Provide columns without a pure-enum cast, with a value to pass through.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function otherColumnProvider(): iterable
    {
        yield 'backed enum' => ['status', 'active'];
        yield 'backed enum list' => ['status', ['active', 'ACTIVE']];
        yield 'plain column' => ['name', 'mobile_app'];
        yield 'plain column list' => ['name', ['web', 'Web', 1]];
        yield 'uncast column' => ['missing', 'web'];
    }

    /**
     * Test that a column without a pure-enum cast is filtered by the raw value.
     *
     * @param  string  $column
     * @param  mixed  $value
     * @return void
     */
    #[DataProvider('otherColumnProvider')]
    public function testColumnWithoutAPureEnumCastIsLeftAlone(string $column, mixed $value): void
    {
        self::assertSame($value, PureEnumFilterValue::resolve(new Device, $column, $value));
    }
}
