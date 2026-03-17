<?php

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\FlushStrategy;

/**
 * Tests for the FlushStrategy enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FlushStrategy::class)]
class FlushStrategyTest extends TestCase
{
    /**
     * Test that LOG has backing value 'log'.
     *
     * @return void
     */
    public function testLogCaseHasCorrectBackingValue(): void
    {
        static::assertSame('log', FlushStrategy::LOG->value);
    }

    /**
     * Test that THROW has backing value 'throw'.
     *
     * @return void
     */
    public function testThrowCaseHasCorrectBackingValue(): void
    {
        static::assertSame('throw', FlushStrategy::THROW->value);
    }

    /**
     * Test that COLLECT has backing value 'collect'.
     *
     * @return void
     */
    public function testCollectCaseHasCorrectBackingValue(): void
    {
        static::assertSame('collect', FlushStrategy::COLLECT->value);
    }

    /**
     * Test that from() resolves valid string values to the correct
     * enum cases.
     *
     * @return void
     */
    public function testFromStringResolvesValidCases(): void
    {
        static::assertSame(FlushStrategy::LOG, FlushStrategy::from('log'));
        static::assertSame(FlushStrategy::THROW, FlushStrategy::from('throw'));
        static::assertSame(FlushStrategy::COLLECT, FlushStrategy::from('collect'));
    }

    /**
     * Test that from() throws a ValueError for an invalid backing
     * value.
     *
     * @return void
     */
    public function testFromStringThrowsForInvalidValue(): void
    {
        $this->expectException(\ValueError::class);

        FlushStrategy::from('invalid');
    }
}
