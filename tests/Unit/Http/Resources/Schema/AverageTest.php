<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
    /** @var string The schema key for posts/id without alias. */
    private const string AVG_KEY_POSTS_ID = '__avg__:posts_id';

    /**
     * Test that of creates an average definition with the correct metric.
     *
     * @return void
     */
    public function testOfCreatesAverageDefinitionWithAvgMetric(): void
    {
        $avg   = Average::of('posts', 'id');
        $array = $avg->toArray();

        self::assertArrayHasKey(self::AVG_KEY_POSTS_ID, $array);
        self::assertSame('avg', $array[self::AVG_KEY_POSTS_ID]['metric']);
    }

    /**
     * Test that the schema key carries the __avg__ prefix.
     *
     * @return void
     */
    public function testSchemaKeyCarriesAvgPrefix(): void
    {
        $avg = Average::of('posts', 'id');
        $key = array_key_first($avg->toArray());

        self::assertStringStartsWith('__avg__:', $key);
    }

    /**
     * Test that Average is distinct from other aggregate types by its metric.
     *
     * @return void
     */
    public function testAverageMetricIsAvgNotSum(): void
    {
        $avg   = Average::of('posts', 'id');
        $array = $avg->toArray();

        self::assertSame('avg', $array[self::AVG_KEY_POSTS_ID]['metric']);
        self::assertNotSame('sum', $array[self::AVG_KEY_POSTS_ID]['metric']);
    }

    /**
     * Test that of with an alias produces the correct schema key.
     *
     * @return void
     */
    public function testOfWithAliasProducesAliasedKey(): void
    {
        $avg   = Average::of('posts', 'id', 'post_id_avg');
        $array = $avg->toArray();

        self::assertArrayHasKey('__avg__:post_id_avg', $array);
        self::assertSame('post_id_avg', $array['__avg__:post_id_avg']['key']);
    }

    /**
     * Test that default() marks the average as a default metric.
     *
     * @return void
     */
    public function testDefaultMarksAverageAsDefault(): void
    {
        $avg   = Average::of('posts', 'id')->default();
        $array = $avg->toArray();

        self::assertTrue($array[self::AVG_KEY_POSTS_ID]['default']);
    }

    /**
     * Test that as() with an average re-keys the output under the new alias.
     *
     * @return void
     */
    public function testAsSetsAliasAndRekeysOutput(): void
    {
        $avg = Average::of('posts', 'id');

        $avg->as('avg_alias');

        $array = $avg->toArray();

        self::assertArrayHasKey('__avg__:avg_alias', $array);
        self::assertSame('avg_alias', $array['__avg__:avg_alias']['key']);
    }
}
