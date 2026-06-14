<?php

namespace Tests\Unit\RouteLinting;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\AllowlistEntry;
use SineMacula\ApiToolkit\RouteLinting\ExemptionAllowlist;
use Tests\TestCase;

/**
 * Tests for the ExemptionAllowlist domain service.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExemptionAllowlist::class)]
class ExemptionAllowlistTest extends TestCase
{
    /**
     * Test that an empty allowlist never exempts any route and reports no
     * unmatched entries.
     *
     * @return void
     */
    public function testEmptyAllowlistExemptsNothing(): void
    {
        // Arrange
        $allowlist = new ExemptionAllowlist([]);

        // Act & Assert
        static::assertFalse($allowlist->isExempt('users.index', 'users'));
        static::assertFalse($allowlist->isExempt(null, 'users'));
        static::assertSame([], $allowlist->unmatched());
    }

    /**
     * Test that an entry matching a route by its exact name exempts that route.
     *
     * @return void
     */
    public function testMatchesByExactRouteName(): void
    {
        // Arrange
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('users.store', 'Legacy store endpoint kept for backward compat.'),
        ]);

        // Act & Assert — exact name match exempts
        static::assertTrue($allowlist->isExempt('users.store', 'users'));

        // A different name does not match even when the URI would
        static::assertFalse($allowlist->isExempt('users.index', 'users'));
    }

    /**
     * Test that an entry with a URI wildcard pattern exempts routes whose URI
     * matches the pattern via fnmatch() shell-wildcard semantics.
     *
     * @return void
     */
    public function testMatchesByUriWildcardPattern(): void
    {
        // Arrange
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('users/*', 'All user sub-routes are exempted for migration.'),
        ]);

        // Act & Assert — wildcard matches the URI
        static::assertTrue($allowlist->isExempt(null, 'users/create'));
        static::assertTrue($allowlist->isExempt(null, 'users/profile'));

        // A URI that does not match the pattern is not exempt
        static::assertFalse($allowlist->isExempt(null, 'articles/create'));
    }

    /**
     * Test that an entry that matched no live route appears in unmatched(), and
     * that an entry that was matched at least once does not.
     *
     * @return void
     */
    public function testUnmatchedEntryIsReportedStale(): void
    {
        // Arrange — two entries; only the first will be matched
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('users.store', 'Legacy store endpoint.'),
            new AllowlistEntry('articles.old', 'Deprecated article route.'),
        ]);

        // Act — match the first entry once
        $allowlist->isExempt('users.store', 'users');

        // Assert — only the unmatched second entry is stale
        static::assertSame(['articles.old'], $allowlist->unmatched());
    }

    /**
     * Test that unmatched() returns stale match keys sorted ascending
     * regardless of the order they appear in the entry list.
     *
     * @return void
     */
    public function testUnmatchedIsSorted(): void
    {
        // Arrange — three entries, none matched
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('users/*', 'User sub-routes.'),
            new AllowlistEntry('articles.old', 'Old article route.'),
            new AllowlistEntry('beta/feature', 'Beta feature.'),
        ]);

        // Act — no calls to isExempt(), so all entries are stale

        // Assert — keys returned in ascending lexicographic order
        static::assertSame(
            ['articles.old', 'beta/feature', 'users/*'],
            $allowlist->unmatched(),
        );
    }
}
