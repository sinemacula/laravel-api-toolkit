<?php

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\Count;

/**
 * Tests for the Count schema definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Count::class)]
class CountTest extends TestCase
{
    /**
     * Test that of creates a count definition with the given key.
     *
     * @return void
     */
    public function testOfCreatesCountDefinition(): void
    {
        $count = Count::of('posts');

        $array = $count->toArray();

        static::assertArrayHasKey('__count__:posts', $array);
        static::assertSame('posts', $array['__count__:posts']['key']);
        static::assertSame('count', $array['__count__:posts']['metric']);
        static::assertSame('posts', $array['__count__:posts']['relation']);
    }

    /**
     * Test that of with an alias uses the alias in the key.
     *
     * @return void
     */
    public function testOfWithAliasUsesAliasInKey(): void
    {
        $count = Count::of('posts', 'post_count');

        $array = $count->toArray();

        static::assertArrayHasKey('__count__:post_count', $array);
        static::assertSame('post_count', $array['__count__:post_count']['key']);
        static::assertSame('posts', $array['__count__:post_count']['relation']);
    }

    /**
     * Test that as sets an alias.
     *
     * @return void
     */
    public function testAsSetsAlias(): void
    {
        $count = Count::of('posts');

        $result = $count->as('total_posts');

        static::assertSame($count, $result);

        $array = $count->toArray();

        static::assertArrayHasKey('__count__:total_posts', $array);
        static::assertSame('total_posts', $array['__count__:total_posts']['key']);
    }

    /**
     * Test that constrain sets a query constraint.
     *
     * @return void
     */
    public function testConstrainSetsQueryConstraint(): void
    {
        $constraint = fn ($query) => $query->where('published', true);
        $count      = Count::of('posts');

        $result = $count->constrain($constraint);

        static::assertSame($count, $result);

        $array = $count->toArray();

        static::assertSame($constraint, $array['__count__:posts']['constraint']);
    }

    /**
     * Test that default marks the count as default.
     *
     * @return void
     */
    public function testDefaultMarksCountAsDefault(): void
    {
        $count = Count::of('posts');

        $result = $count->default();

        static::assertSame($count, $result);

        $array = $count->toArray();

        static::assertTrue($array['__count__:posts']['default']);
    }

    /**
     * Test that toArray returns the __count__ prefixed key with metric=count.
     *
     * @return void
     */
    public function testToArrayReturnsCountPrefixedKeyWithMetric(): void
    {
        $count = Count::of('comments');

        $array = $count->toArray();

        static::assertCount(1, $array);
        $key = array_key_first($array);
        static::assertStringStartsWith('__count__:', $key);
        static::assertSame('count', $array[$key]['metric']);
    }

    /**
     * Test that toArray excludes unset optional values.
     *
     * @return void
     */
    public function testToArrayExcludesUnsetOptionalValues(): void
    {
        $count = Count::of('posts');

        $array = $count->toArray();

        static::assertArrayNotHasKey('constraint', $array['__count__:posts']);
        static::assertArrayNotHasKey('default', $array['__count__:posts']);
        static::assertArrayNotHasKey('extras', $array['__count__:posts']);
        static::assertArrayNotHasKey('guards', $array['__count__:posts']);
        static::assertArrayNotHasKey('transformers', $array['__count__:posts']);
    }

    /**
     * Test that toArray includes all properties when fully configured.
     *
     * @return void
     */
    public function testToArrayIncludesAllPropertiesWhenFullyConfigured(): void
    {
        $constraint  = fn ($query) => $query->where('active', true);
        $guard       = fn () => true;
        $transformer = fn ($resource, $value) => $value;

        $count = Count::of('posts', 'active_posts')
            ->constrain($constraint)
            ->default()
            ->guard($guard)
            ->transform($transformer)
            ->extras('posts.comments');

        $array = $count->toArray();
        $data  = $array['__count__:active_posts'];

        static::assertSame('active_posts', $data['key']);
        static::assertSame('count', $data['metric']);
        static::assertSame('posts', $data['relation']);
        static::assertSame($constraint, $data['constraint']);
        static::assertTrue($data['default']);
        static::assertSame([$guard], $data['guards']);
        static::assertSame([$transformer], $data['transformers']);
        static::assertSame(['posts.comments'], $data['extras']);
    }
}
