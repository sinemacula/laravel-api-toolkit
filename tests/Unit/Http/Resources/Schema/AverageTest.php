<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Schema\Average;

/**
 * Tests for the Average schema definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Average::class)]
final class AverageTest extends TestCase
{
    /** @var string The schema key for the unaliased definition. */
    private const string DEFAULT_KEY = '__aggregate__:orders_avg_total';

    /**
     * Test that the function is avg.
     *
     * @return void
     */
    public function testFunctionIsAVG(): void
    {
        self::assertSame(AggregateFunction::AVG, Average::of('orders', 'total')->function());
    }

    /**
     * Test that of keys the definition by Laravel's default aggregate key.
     *
     * @return void
     */
    public function testOfKeysTheDefinitionByTheDefaultKey(): void
    {
        $array = Average::of('orders', 'total')->toArray();

        self::assertSame([self::DEFAULT_KEY], array_keys($array));
        self::assertSame('orders_avg_total', $array[self::DEFAULT_KEY]['key']);
        self::assertSame('avg', $array[self::DEFAULT_KEY]['metric']);
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
        $array = Average::of('orders', 'total', 'headline')->toArray();

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
        $array = Average::of('orders', 'total')->default()->toArray();

        self::assertTrue($array[self::DEFAULT_KEY]['default']);
    }
}
