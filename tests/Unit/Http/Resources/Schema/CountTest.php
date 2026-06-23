<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\Count;
use SineMacula\ApiToolkit\Schema\OpenApiFieldSchema;

/**
 * Tests for the Count schema definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Count::class)]
final class CountTest extends TestCase
{
    /** @var string The count key for the posts relation. */
    private const string COUNT_KEY_POSTS = '__count__:posts';

    /** @var string The count key for the post_count attribute. */
    private const string COUNT_KEY_POST_COUNT = '__count__:post_count';

    /**
     * Test that of creates a count definition with the given key.
     *
     * @return void
     */
    public function testOfCreatesCountDefinition(): void
    {
        $count = Count::of('posts');

        $array = $count->toArray();

        self::assertArrayHasKey(self::COUNT_KEY_POSTS, $array);
        self::assertSame('posts', $array[self::COUNT_KEY_POSTS]['key']);
        self::assertSame('count', $array[self::COUNT_KEY_POSTS]['metric']);
        self::assertSame('posts', $array[self::COUNT_KEY_POSTS]['relation']);
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

        self::assertArrayHasKey(self::COUNT_KEY_POST_COUNT, $array);
        self::assertSame('post_count', $array[self::COUNT_KEY_POST_COUNT]['key']);
        self::assertSame('posts', $array[self::COUNT_KEY_POST_COUNT]['relation']);
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

        self::assertSame($count, $result);

        $array = $count->toArray();

        self::assertArrayHasKey('__count__:total_posts', $array);
        self::assertSame('total_posts', $array['__count__:total_posts']['key']);
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

        self::assertSame($count, $result);

        $array = $count->toArray();

        self::assertSame($constraint, $array[self::COUNT_KEY_POSTS]['constraint']);
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

        self::assertSame($count, $result);

        $array = $count->toArray();

        self::assertTrue($array[self::COUNT_KEY_POSTS]['default']);
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

        self::assertCount(1, $array);
        $key = array_key_first($array);
        self::assertStringStartsWith('__count__:', $key);
        self::assertSame('count', $array[$key]['metric']);
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

        self::assertArrayNotHasKey('constraint', $array[self::COUNT_KEY_POSTS]);
        self::assertArrayNotHasKey('default', $array[self::COUNT_KEY_POSTS]);
        self::assertArrayNotHasKey('extras', $array[self::COUNT_KEY_POSTS]);
        self::assertArrayNotHasKey('guards', $array[self::COUNT_KEY_POSTS]);
        self::assertArrayNotHasKey('transformers', $array[self::COUNT_KEY_POSTS]);
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

        self::assertSame('active_posts', $data['key']);
        self::assertSame('count', $data['metric']);
        self::assertSame('posts', $data['relation']);
        self::assertSame($constraint, $data['constraint']);
        self::assertTrue($data['default']);
        self::assertSame([$guard], $data['guards']);
        self::assertSame([$transformer], $data['transformers']);
        self::assertSame(['posts.comments'], $data['extras']);
    }

    /**
     * Test that the openapi key is absent when no declaration was made.
     *
     * Backward-compatibility oracle (AC-11): a count with no openapi() call
     * must serialize without the new key.
     *
     * @return void
     */
    public function testToArrayOmitsOpenApiWhenNotDeclared(): void
    {
        $count = Count::of('posts');

        $array = $count->toArray();

        self::assertArrayNotHasKey('openapi', $array[self::COUNT_KEY_POSTS]);
    }

    /**
     * Test that the openapi key is emitted when a declaration was made.
     *
     * @return void
     */
    public function testToArrayIncludesOpenApiWhenDeclared(): void
    {
        $count = Count::of('posts');
        $count->openapi()->type('integer')->description('Number of posts');

        $array = $count->toArray();

        self::assertArrayHasKey('openapi', $array[self::COUNT_KEY_POSTS]);
        self::assertInstanceOf(OpenApiFieldSchema::class, $array[self::COUNT_KEY_POSTS]['openapi']);
        self::assertSame('integer', $array[self::COUNT_KEY_POSTS]['openapi']->type);
    }
}
