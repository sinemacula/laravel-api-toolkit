<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
    /** @var string The schema key for posts/id without alias. */
    private const string SUM_KEY_POSTS_ID = '__sum__:posts_id';

    /**
     * Test that of creates a sum definition with the correct metric.
     *
     * @return void
     */
    public function testOfCreatesSumDefinitionWithSumMetric(): void
    {
        $sum   = Sum::of('posts', 'id');
        $array = $sum->toArray();

        self::assertArrayHasKey(self::SUM_KEY_POSTS_ID, $array);
        self::assertSame('sum', $array[self::SUM_KEY_POSTS_ID]['metric']);
    }

    /**
     * Test that the schema key carries the __sum__ prefix.
     *
     * @return void
     */
    public function testSchemaKeyCarriesSumPrefix(): void
    {
        $sum = Sum::of('posts', 'id');
        $key = array_key_first($sum->toArray());

        self::assertStringStartsWith('__sum__:', $key);
    }

    /**
     * Test that Sum is distinct from other aggregate types by its metric.
     *
     * @return void
     */
    public function testSumMetricIsSumNotAvg(): void
    {
        $sum   = Sum::of('posts', 'id');
        $array = $sum->toArray();

        self::assertSame('sum', $array[self::SUM_KEY_POSTS_ID]['metric']);
        self::assertNotSame('avg', $array[self::SUM_KEY_POSTS_ID]['metric']);
    }

    /**
     * Test that of with an alias produces the correct schema key.
     *
     * @return void
     */
    public function testOfWithAliasProducesAliasedKey(): void
    {
        $sum   = Sum::of('posts', 'id', 'post_id_sum');
        $array = $sum->toArray();

        self::assertArrayHasKey('__sum__:post_id_sum', $array);
        self::assertSame('post_id_sum', $array['__sum__:post_id_sum']['key']);
    }

    /**
     * Test that default() marks the sum as a default metric.
     *
     * @return void
     */
    public function testDefaultMarksSumAsDefault(): void
    {
        $sum   = Sum::of('posts', 'id')->default();
        $array = $sum->toArray();

        self::assertTrue($array[self::SUM_KEY_POSTS_ID]['default']);
    }
}
