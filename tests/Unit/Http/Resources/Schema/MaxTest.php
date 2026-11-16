<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Schema\Max;

/**
 * Tests for the Max schema definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Max::class)]
final class MaxTest extends TestCase
{
    /** @var string The schema key for the unaliased definition. */
    private const string DEFAULT_KEY = '__aggregate__:order_items_max_created_at';

    /**
     * Test that the function is max.
     *
     * @return void
     */
    public function testFunctionIsMAX(): void
    {
        self::assertSame(AggregateFunction::MAX, Max::of('orderItems', 'created_at')->function());
    }

    /**
     * Test that of keys the definition by Laravel's default aggregate key.
     *
     * @return void
     */
    public function testOfKeysTheDefinitionByTheDefaultKey(): void
    {
        $array = Max::of('orderItems', 'created_at')->toArray();

        self::assertSame([self::DEFAULT_KEY], array_keys($array));
        self::assertSame('order_items_max_created_at', $array[self::DEFAULT_KEY]['key']);
        self::assertSame('max', $array[self::DEFAULT_KEY]['metric']);
        self::assertSame('orderItems', $array[self::DEFAULT_KEY]['relation']);
        self::assertSame('created_at', $array[self::DEFAULT_KEY]['column']);
    }

    /**
     * Test that of with an alias keys the definition by the alias.
     *
     * @return void
     */
    public function testOfWithAliasKeysTheDefinitionByTheAlias(): void
    {
        $array = Max::of('orderItems', 'created_at', 'headline')->toArray();

        self::assertSame(['__aggregate__:headline'], array_keys($array));
        self::assertSame('headline', $array['__aggregate__:headline']['key']);
        self::assertSame('created_at', $array['__aggregate__:headline']['column']);
    }

    /**
     * Test that default() marks the aggregate as a default.
     *
     * @return void
     */
    public function testDefaultMarksTheAggregateAsDefault(): void
    {
        $array = Max::of('orderItems', 'created_at')->default()->toArray();

        self::assertTrue($array[self::DEFAULT_KEY]['default']);
    }
}
