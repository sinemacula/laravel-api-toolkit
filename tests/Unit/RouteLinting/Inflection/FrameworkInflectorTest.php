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

    /**
     * Test that an uncountable word where Str::singular() returns the same
     * value is still reported as plural-safe by isPlural().
     *
     * Targets ReturnRemoval on the uncountable `return true` in isPlural():
     * without that return, 'data' would fall through to
     * `Str::singular($word) !== $word`, which evaluates to 'data' !== 'data'
     * = false, incorrectly reporting 'data' as not plural.
     *
     * @return void
     */
    public function testUncountableWhoseSingularFormMatchesItselfIsPlural(): void
    {
        // `Str::singular('data')` returns 'data' (unchanged), so without the
        // early return the result would be false.
        $inflector = new FrameworkInflector(['data']);

        static::assertTrue($inflector->isPlural('data'));
    }

    /**
     * Test that isPlural() is case-insensitive for uncountable matching —
     * 'DATA' (uppercase) must be treated as plural-safe when 'data' is configured.
     *
     * Targets UnwrapStrToLower on the uncountable in_array check in isPlural():
     * without strtolower(), in_array('DATA', ['data']) returns false; the
     * method then delegates to Str::singular('DATA') which returns 'DATA'
     * (unchanged), so Str::singular != word evaluates false — isPlural returns
     * false instead of the expected true.
     *
     * @return void
     */
    public function testUncountableMatchingIsCaseInsensitiveForWordsThatDoNotChangeSingular(): void
    {
        // 'data' is an uncountable whose Str::singular() == itself.
        // Without strtolower(), 'DATA' would not be found in ['data'] and
        // Str::singular('DATA') === 'DATA' would make isPlural return false.
        $inflector = new FrameworkInflector(['data']);

        static::assertTrue($inflector->isPlural('DATA'));
        static::assertTrue($inflector->isPlural('Data'));
    }
}
