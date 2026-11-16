<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Docs\TagList;
use SineMacula\ApiToolkit\OpenApi\Exceptions\OrphanedDocumentationException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\TagConfigurationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes;
use Tests\Concerns\BuildsDocumentedRoutes;
use Tests\Concerns\WritesDocsDirectories;
use Tests\TestCase;

/**
 * Tests for the top-level tag list.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(TagList::class)]
final class TagListTest extends TestCase
{
    use BuildsDocumentedRoutes, WritesDocsDirectories;

    /**
     * Point the docs directory somewhere empty.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->useDocsDirectory();
    }

    /**
     * Test that the list is off with no order and no tag file.
     *
     * @return void
     */
    public function testListIsOffWithoutAnOrderOrATagFile(): void
    {
        $this->useDocsDirectory(['operations/users.index.md' => 'Lists the users.']);

        self::assertNull((new TagList)->for('public', $this->routes(['public' => ['User']])));

        config()->set('api-toolkit.openapi.tags', null);

        self::assertNull((new TagList)->for('public', $this->routes(['public' => ['User']])));
    }

    /**
     * Test that a tag file under another audience alone turns the list on.
     *
     * @return void
     */
    public function testTagFileUnderAnotherAudienceTurnsTheListOn(): void
    {
        $this->useDocsDirectory(['audiences/internal/tags/User.md' => 'Staff accounts.']);

        $routes = $this->routes(['public' => ['User', 'Article'], 'internal' => ['User']]);

        self::assertSame([['name' => 'Article'], ['name' => 'User']], (new TagList)->for('public', $routes));
        self::assertSame([['name' => 'User', 'description' => 'Staff accounts.']], (new TagList)->for('internal', $routes));
    }

    /**
     * Provide tag orders that are not a list of distinct, non-empty names.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function malformedOrders(): iterable
    {
        yield 'not an array' => ['User'];
        yield 'a map' => [['first' => 'User']];
        yield 'a non-string' => [['User', 7]];
        yield 'an empty name' => [['User', '']];
        yield 'a duplicate' => [['User', 'Article', 'User']];
    }

    /**
     * Test that a malformed order fails even while the list is off.
     *
     * @param  mixed  $order
     * @return void
     */
    #[DataProvider('malformedOrders')]
    public function testMalformedOrderFailsEvenWhileTheListIsOff(mixed $order): void
    {
        config()->set('api-toolkit.openapi.tags', $order);

        $this->expectException(TagConfigurationException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote(
            'Unable to export the OpenAPI tags: api-toolkit.openapi.tags must be a list of distinct, non-empty tag names.',
            '/',
        ) . '$/');

        (new TagList)->for('public', $this->routes(['public' => ['User', 'Article', '7']]));
    }

    /**
     * Test that ordered tags lead and the rest follow naturally, ignoring case,
     * with exact ties broken byte-wise.
     *
     * @return void
     */
    public function testOrderedTagsLeadAndTheRestFollowNaturally(): void
    {
        config()->set('api-toolkit.openapi.tags', ['Widget', 'Account']);

        $routes = $this->routes(['public' => ['item10', 'Account', 'item2', 'apple', 'Widget', 'Item2'], 'internal' => ['Staff']]);

        self::assertSame(
            [['name' => 'Widget'], ['name' => 'Account'], ['name' => 'apple'], ['name' => 'Item2'], ['name' => 'item2'], ['name' => 'item10']],
            (new TagList)->for('public', $routes),
        );
    }

    /**
     * Test that an ordered tag another audience uses is left out of this one.
     *
     * @return void
     */
    public function testTagUnusedByTheAudienceIsLeftOut(): void
    {
        config()->set('api-toolkit.openapi.tags', ['Staff', 'User']);

        $routes = $this->routes(['public' => ['User'], 'internal' => ['Staff', 'User']]);

        self::assertSame([['name' => 'User']], (new TagList)->for('public', $routes));
        self::assertSame([['name' => 'Staff'], ['name' => 'User']], (new TagList)->for('internal', $routes));
    }

    /**
     * Test that descriptions are attached and empty ones left out.
     *
     * @return void
     */
    public function testDescriptionsAreAttachedAndEmptyOnesLeftOut(): void
    {
        $this->useDocsDirectory([
            'tags/User.md'    => "Accounts.\n",
            'tags/Article.md' => " \n",
        ]);

        self::assertSame(
            [['name' => 'Article'], ['name' => 'User', 'description' => 'Accounts.'], ['name' => 'Widget']],
            (new TagList)->for('public', $this->routes(['public' => ['Widget', 'User', 'Article']])),
        );
    }

    /**
     * Test that an ordered tag no audience uses fails.
     *
     * @return void
     */
    public function testOrderedTagNoAudienceUsesFails(): void
    {
        config()->set('api-toolkit.openapi.tags', ['User', 'Ghost']);

        $this->expectException(TagConfigurationException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote(
            'Unable to export the OpenAPI tags: api-toolkit.openapi.tags names [Ghost], which no audience uses.',
            '/',
        ) . '$/');

        (new TagList)->for('public', $this->routes(['public' => ['User'], 'internal' => ['Staff']]));
    }

    /**
     * Test that a tag file matching no tag fails.
     *
     * @return void
     */
    public function testOrphanedTagFileFails(): void
    {
        $dir = $this->useDocsDirectory(['tags/Ghost.md' => 'Ghost.']);

        $this->expectException(OrphanedDocumentationException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote(sprintf('The tag description [%s/tags/Ghost.md] matches no tag in any audience.', $dir), '/') . '$/');

        (new TagList)->for('public', $this->routes(['public' => ['User']]));
    }

    /**
     * Test that an audience using no tag gets no list.
     *
     * @return void
     */
    public function testAudienceUsingNoTagGetsNoList(): void
    {
        config()->set('api-toolkit.openapi.tags', ['User']);

        self::assertNull((new TagList)->for('partner', $this->routes(['public' => ['User'], 'partner' => []])));
    }

    /**
     * Build routes, one per tag, for each audience.
     *
     * @param  array<string, list<string>>  $tagsByAudience
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes
     */
    private function routes(array $tagsByAudience): DocumentedRoutes
    {
        $byAudience = [];

        foreach ($tagsByAudience as $audience => $tags) {
            $byAudience[$audience] = array_map(static fn (string $tag) => self::documentedRoute('/' . $audience . '/' . $tag, tag: $tag), $tags);
        }

        return new DocumentedRoutes($byAudience, false);
    }
}
