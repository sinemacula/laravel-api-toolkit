<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Schema\Min;

/**
 * Tests for the Min schema definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Min::class)]
final class MinTest extends TestCase
{
    /** @var string The schema key for the unaliased definition. */
    private const string DEFAULT_KEY = '__aggregate__:variants_min_price';

    /**
     * Test that the function is min.
     *
     * @return void
     */
    public function testFunctionIsMIN(): void
    {
        self::assertSame(AggregateFunction::MIN, Min::of('variants', 'price')->function());
    }

    /**
     * Test that of keys the definition by Laravel's default aggregate key.
     *
     * @return void
     */
    public function testOfKeysTheDefinitionByTheDefaultKey(): void
    {
        $array = Min::of('variants', 'price')->toArray();

        self::assertSame([self::DEFAULT_KEY], array_keys($array));
        self::assertSame('variants_min_price', $array[self::DEFAULT_KEY]['key']);
        self::assertSame('min', $array[self::DEFAULT_KEY]['metric']);
        self::assertSame('variants', $array[self::DEFAULT_KEY]['relation']);
        self::assertSame('price', $array[self::DEFAULT_KEY]['column']);
    }

    /**
     * Test that of with an alias keys the definition by the alias.
     *
     * @return void
     */
    public function testOfWithAliasKeysTheDefinitionByTheAlias(): void
    {
        $array = Min::of('variants', 'price', 'headline')->toArray();

        self::assertSame(['__aggregate__:headline'], array_keys($array));
        self::assertSame('headline', $array['__aggregate__:headline']['key']);
        self::assertSame('price', $array['__aggregate__:headline']['column']);
    }

    /**
     * Test that default() marks the aggregate as a default.
     *
     * @return void
     */
    public function testDefaultMarksTheAggregateAsDefault(): void
    {
        $array = Min::of('variants', 'price')->default()->toArray();

        self::assertTrue($array[self::DEFAULT_KEY]['default']);
    }
}
