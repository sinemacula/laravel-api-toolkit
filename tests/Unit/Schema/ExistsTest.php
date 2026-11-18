<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Schema\Exists;

/**
 * Tests for the Exists schema definition.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Exists::class)]
final class ExistsTest extends TestCase
{
    /** @var string The schema key for the unaliased definition. */
    private const string DEFAULT_KEY = '__aggregate__:posts_exists';

    /**
     * Test that the function is exists.
     *
     * @return void
     */
    public function testFunctionIsEXISTS(): void
    {
        self::assertSame(AggregateFunction::EXISTS, Exists::of('posts')->function());
    }

    /**
     * Test that of keys the definition by Laravel's default aggregate key and
     * carries no column.
     *
     * @return void
     */
    public function testOfKeysTheDefinitionByTheDefaultKeyWithoutAColumn(): void
    {
        $array = Exists::of('posts')->toArray();

        self::assertSame([self::DEFAULT_KEY], array_keys($array));
        self::assertSame('posts_exists', $array[self::DEFAULT_KEY]['key']);
        self::assertSame('exists', $array[self::DEFAULT_KEY]['metric']);
        self::assertSame('posts', $array[self::DEFAULT_KEY]['relation']);
        self::assertArrayNotHasKey('column', $array[self::DEFAULT_KEY]);
    }

    /**
     * Test that of with an alias keys the definition by the alias.
     *
     * @return void
     */
    public function testOfWithAliasKeysTheDefinitionByTheAlias(): void
    {
        $array = Exists::of('posts', 'published')->toArray();

        self::assertSame(['__aggregate__:published'], array_keys($array));
        self::assertSame('published', $array['__aggregate__:published']['key']);
        self::assertSame('posts', $array['__aggregate__:published']['relation']);
    }

    /**
     * Test that the fluent modifiers carry through to the array.
     *
     * @return void
     */
    public function testModifiersCarryThroughToTheArray(): void
    {
        $constraint = fn ($query) => $query->where('published', true);

        $array = Exists::of('posts')->as('published')->constrain($constraint)->default()->toArray();

        self::assertSame($constraint, $array['__aggregate__:published']['constraint']);
        self::assertTrue($array['__aggregate__:published']['default']);
    }
}
