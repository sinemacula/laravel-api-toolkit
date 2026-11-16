<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Schema\Sum;

/**
 * Tests for the Sum schema definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Sum::class)]
final class SumTest extends TestCase
{
    /** @var string The schema key for the unaliased definition. */
    private const string DEFAULT_KEY = '__aggregate__:orders_sum_total';

    /**
     * Test that the function is sum.
     *
     * @return void
     */
    public function testFunctionIsSUM(): void
    {
        self::assertSame(AggregateFunction::SUM, Sum::of('orders', 'total')->function());
    }

    /**
     * Test that of keys the definition by Laravel's default aggregate key.
     *
     * @return void
     */
    public function testOfKeysTheDefinitionByTheDefaultKey(): void
    {
        $array = Sum::of('orders', 'total')->toArray();

        self::assertSame([self::DEFAULT_KEY], array_keys($array));
        self::assertSame('orders_sum_total', $array[self::DEFAULT_KEY]['key']);
        self::assertSame('sum', $array[self::DEFAULT_KEY]['metric']);
        self::assertSame('orders', $array[self::DEFAULT_KEY]['relation']);
        self::assertSame('total', $array[self::DEFAULT_KEY]['column']);
    }

    /**
     * Test that of with an alias keys the definition by the alias.
     *
     * @return void
     */
    public function testOfWithAliasKeysTheDefinitionByTheAlias(): void
    {
        $array = Sum::of('orders', 'total', 'headline')->toArray();

        self::assertSame(['__aggregate__:headline'], array_keys($array));
        self::assertSame('headline', $array['__aggregate__:headline']['key']);
        self::assertSame('total', $array['__aggregate__:headline']['column']);
    }

    /**
     * Test that default() marks the aggregate as a default.
     *
     * @return void
     */
    public function testDefaultMarksTheAggregateAsDefault(): void
    {
        $array = Sum::of('orders', 'total')->default()->toArray();

        self::assertTrue($array[self::DEFAULT_KEY]['default']);
    }
}
