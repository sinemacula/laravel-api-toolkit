<?php

namespace Tests\Unit\RouteLinting\Inflection;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Inflection\FrameworkInflector;
use Tests\TestCase;

/**
 * Tests for FrameworkInflector.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FrameworkInflector::class)]
class FrameworkInflectorTest extends TestCase
{
    /**
     * Test that singular() returns the singular form of a regular plural word.
     *
     * @return void
     */
    public function testSingularisesAPlural(): void
    {
        $inflector = new FrameworkInflector;

        static::assertSame('user', $inflector->singular('users'));
    }

    /**
     * Test that a configured uncountable is returned unchanged by singular() and
     * is reported as plural-safe by isPlural().
     *
     * @return void
     */
    public function testUncountableIsReturnedUnchangedAndPluralSafe(): void
    {
        $inflector = new FrameworkInflector(['media']);

        static::assertSame('media', $inflector->singular('media'));
        static::assertTrue($inflector->isPlural('media'));
    }

    /**
     * Test that isPlural() correctly identifies plural and singular words.
     *
     * @return void
     */
    public function testIsPluralDetectsPlurality(): void
    {
        $inflector = new FrameworkInflector;

        static::assertTrue($inflector->isPlural('users'));
        static::assertFalse($inflector->isPlural('user'));
    }

    /**
     * Test that an empty string is returned unchanged by singular() and
     * isPlural() returns false for an empty string.
     *
     * @return void
     */
    public function testEmptyStringEdgeCases(): void
    {
        $inflector = new FrameworkInflector;

        static::assertSame('', $inflector->singular(''));
        static::assertFalse($inflector->isPlural(''));
    }

    /**
     * Test that uncountable matching is case-insensitive.
     *
     * @return void
     */
    public function testUncountableMatchingIsCaseInsensitive(): void
    {
        $inflector = new FrameworkInflector(['media']);

        static::assertSame('Media', $inflector->singular('Media'));
        static::assertTrue($inflector->isPlural('MEDIA'));
    }
}
