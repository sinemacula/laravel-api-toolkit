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
     * Test that an empty allowlist never suppresses any violation and reports no
     * unmatched or unused entries.
     *
     * @return void
     */
    public function testEmptyAllowlistSuppressesNothing(): void
    {
        // Arrange
        $allowlist = new ExemptionAllowlist([]);

        // Act
        $allowlist->observe('users.index', 'users');

        // Assert
        static::assertFalse($allowlist->suppresses('users.index', 'users', 'R1'));
        static::assertSame([], $allowlist->unmatched());
        static::assertSame([], $allowlist->unused());
    }

    /**
     * Test that an entry without a rules list suppresses any rule on a matching
     * route (backward-compatibility: omitting rules means all rules).
     *
     * @return void
     */
    public function testEntryWithoutRulesSuppressesAnyRule(): void
    {
        // Arrange — entry with empty rules list (default)
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('users.store', 'Legacy store endpoint kept for backward compat.'),
        ]);

        // Act & Assert — suppresses any rule ID on the matching route
        static::assertTrue($allowlist->suppresses('users.store', 'users', 'R1'));
        static::assertTrue($allowlist->suppresses('users.store', 'users', 'R9'));
        static::assertTrue($allowlist->suppresses('users.store', 'users', 'R99'));
    }

    /**
     * Test that an entry with an explicit rules list suppresses only the listed
     * rules and leaves other rules unsuppressed on the same route.
     *
     * @return void
     */
    public function testEntryWithRulesSuppressesOnlyListedRules(): void
    {
        // Arrange — entry covering only R1
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('login', 'Legacy auth endpoint.', ['R1']),
        ]);

        // Act & Assert — R1 is suppressed; R2 is not
        static::assertTrue($allowlist->suppresses(null, 'login', 'R1'));
        static::assertFalse($allowlist->suppresses(null, 'login', 'R2'));
    }

    /**
     * Test that suppresses() matches by exact route name.
     *
     * @return void
     */
    public function testSuppressesByExactRouteName(): void
    {
        // Arrange
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('users.store', 'Legacy store endpoint.'),
        ]);

        // Act & Assert — exact name match suppresses
        static::assertTrue($allowlist->suppresses('users.store', 'users', 'R1'));

        // A different name is not suppressed even if the URI matches
        static::assertFalse($allowlist->suppresses('users.index', 'users', 'R1'));
    }

    /**
     * Test that suppresses() matches by URI wildcard pattern via fnmatch().
     *
     * @return void
     */
    public function testSuppressesByUriWildcardPattern(): void
    {
        // Arrange
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('users/*', 'All user sub-routes are exempted for migration.'),
        ]);

        // Act & Assert — wildcard matches the URI
        static::assertTrue($allowlist->suppresses(null, 'users/create', 'R1'));
        static::assertTrue($allowlist->suppresses(null, 'users/profile', 'R2'));

        // A URI that does not match the pattern is not suppressed
        static::assertFalse($allowlist->suppresses(null, 'articles/create', 'R1'));
    }

    /**
     * Test that observe() drives unmatched() — an entry whose pattern never
     * matches any observed route appears in unmatched().
     *
     * @return void
     */
    public function testObserveAndUnmatched(): void
    {
        // Arrange — two entries; only the first will be observed
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('users.store', 'Legacy store endpoint.'),
            new AllowlistEntry('articles.old', 'Deprecated article route.'),
        ]);

        // Act — observe only the route matching the first entry
        $allowlist->observe('users.store', 'users');

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
        // Arrange — three entries, none observed
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('users/*', 'User sub-routes.'),
            new AllowlistEntry('articles.old', 'Old article route.'),
            new AllowlistEntry('beta/feature', 'Beta feature.'),
        ]);

        // Act — no observe() calls, so all entries are unmatched

        // Assert — keys returned in ascending lexicographic order
        static::assertSame(
            ['articles.old', 'beta/feature', 'users/*'],
            $allowlist->unmatched(),
        );
    }

    /**
     * Test that an entry that matched a live route but suppressed no violation
     * appears in unused() with a descriptive string.
     *
     * @return void
     */
    public function testUnusedEntryMatchedRouteButSuppressedNothing(): void
    {
        // Arrange — entry covering R1 only; the route has no R1 violation
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('users.index', 'Waived for migration.', ['R1']),
        ]);

        // Act — observe the route (it is live), but never call suppresses()
        // because no violation fires on it
        $allowlist->observe('users.index', 'users');

        // Assert — entry appears in unused() but not unmatched()
        static::assertSame([], $allowlist->unmatched());
        static::assertCount(1, $allowlist->unused());
        static::assertStringContainsString('users.index', $allowlist->unused()[0]);
        static::assertStringContainsString('suppressed nothing', $allowlist->unused()[0]);
        static::assertStringContainsString('Waived for migration.', $allowlist->unused()[0]);
    }

    /**
     * Test that an entry that actually suppresses a violation does NOT appear
     * in unused().
     *
     * @return void
     */
    public function testUsedEntryDoesNotAppearInUnused(): void
    {
        // Arrange
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('login', 'Legacy auth endpoint.', ['R1']),
        ]);

        // Act — observe and then suppress a violation
        $allowlist->observe(null, 'login');
        $allowlist->suppresses(null, 'login', 'R1');

        // Assert — entry was used; it appears in neither unused() nor unmatched()
        static::assertSame([], $allowlist->unmatched());
        static::assertSame([], $allowlist->unused());
    }

    /**
     * Test that unused() returns entries sorted ascending for determinism.
     *
     * @return void
     */
    public function testUnusedIsSorted(): void
    {
        // Arrange — two entries that both match a live route but suppress nothing
        $allowlist = new ExemptionAllowlist([
            new AllowlistEntry('z-route', 'Last alphabetically.', ['R9']),
            new AllowlistEntry('a-route', 'First alphabetically.', ['R9']),
        ]);

        // Act — observe both routes but never suppress
        $allowlist->observe('z-route', 'z-route');
        $allowlist->observe('a-route', 'a-route');

        // Assert — sorted ascending
        $unused = $allowlist->unused();
        static::assertCount(2, $unused);
        static::assertStringContainsString('a-route', $unused[0]);
        static::assertStringContainsString('z-route', $unused[1]);
    }
}
