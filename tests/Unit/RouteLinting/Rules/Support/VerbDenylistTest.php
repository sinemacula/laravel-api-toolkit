<?php

namespace Tests\Unit\RouteLinting\Rules\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Rules\Support\VerbDenylist;
use Tests\TestCase;

/**
 * Tests for the VerbDenylist membership oracle.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(VerbDenylist::class)]
class VerbDenylistTest extends TestCase
{
    /**
     * Test that contains() returns true for a word that is in the denylist.
     *
     * @return void
     */
    public function testContainsDenylistedVerb(): void
    {
        // Arrange
        $denylist = new VerbDenylist(['get', 'post', 'delete'], []);

        // Act & Assert
        static::assertTrue($denylist->contains('get'));
        static::assertTrue($denylist->contains('post'));
        static::assertTrue($denylist->contains('delete'));
    }

    /**
     * Test that contains() returns false for a word not in the denylist.
     *
     * @return void
     */
    public function testDoesNotContainAbsentVerb(): void
    {
        // Arrange
        $denylist = new VerbDenylist(['get', 'post'], []);

        // Act & Assert
        static::assertFalse($denylist->contains('users'));
        static::assertFalse($denylist->contains('transfer'));
    }

    /**
     * Test that constructing without a verb makes contains() return false for it
     * (homograph removal — removing from denylist is the tuning surface).
     *
     * @return void
     */
    public function testRemovingWordMakesContainsFalse(): void
    {
        // Arrange — denylist constructed without 'transfer'
        $denylist = new VerbDenylist(['get', 'post', 'delete'], []);

        // Act & Assert — 'transfer' was never added, so it must not be present
        static::assertFalse($denylist->contains('transfer'));
    }

    /**
     * Test that contains() is case-insensitive, normalising both sides to lowercase.
     *
     * @return void
     */
    public function testContainsIsCaseInsensitive(): void
    {
        // Arrange
        $denylist = new VerbDenylist(['GET', 'Post'], []);

        // Act & Assert
        static::assertTrue($denylist->contains('get'));
        static::assertTrue($denylist->contains('GET'));
        static::assertTrue($denylist->contains('post'));
        static::assertTrue($denylist->contains('POST'));
    }

    /**
     * Test that hint() returns the configured remediation hint for a known verb.
     *
     * @return void
     */
    public function testHintReturnsConfiguredHintOrNull(): void
    {
        // Arrange
        $denylist = new VerbDenylist(
            ['login', 'logout'],
            ['login' => 'Use POST /sessions instead.', 'logout' => 'Use DELETE /sessions/{session} instead.'],
        );

        // Act & Assert — mapped verbs return their hint
        static::assertSame('Use POST /sessions instead.', $denylist->hint('login'));
        static::assertSame('Use DELETE /sessions/{session} instead.', $denylist->hint('logout'));

        // A verb in the denylist but absent from hints returns null
        $denylistWithoutHint = new VerbDenylist(['get'], []);
        static::assertNull($denylistWithoutHint->hint('get'));
    }

    /**
     * Test that an empty denylist means contains() always returns false.
     *
     * @return void
     */
    public function testEmptyDenylistAlwaysReturnsFalse(): void
    {
        // Arrange
        $denylist = new VerbDenylist([], []);

        // Act & Assert
        static::assertFalse($denylist->contains('get'));
        static::assertFalse($denylist->contains('post'));
        static::assertFalse($denylist->contains(''));
    }

    /**
     * Test that hint() returns null for an unmapped verb regardless of denylist membership.
     *
     * @return void
     */
    public function testHintReturnsNullForUnmappedVerb(): void
    {
        // Arrange — verb is in denylist but has no hint entry
        $denylist = new VerbDenylist(['fetch'], []);

        // Act & Assert
        static::assertNull($denylist->hint('fetch'));
        static::assertNull($denylist->hint('unknown'));
    }
}
