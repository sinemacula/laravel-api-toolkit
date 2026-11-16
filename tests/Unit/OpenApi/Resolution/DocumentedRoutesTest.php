<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Resolution\DocumentedRoutes;
use Tests\Concerns\BuildsDocumentedRoutes;

/**
 * Tests for the documented route inventory.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DocumentedRoutes::class)]
final class DocumentedRoutesTest extends TestCase
{
    use BuildsDocumentedRoutes;

    /**
     * Test that the audiences are listed and each keeps its shadowed routes.
     *
     * @return void
     */
    public function testListsTheAudiencesAndKeepsShadowedRoutes(): void
    {
        $first  = self::documentedRoute('/a');
        $second = self::documentedRoute('/a');
        $routes = new DocumentedRoutes(['public' => [$first, $second], 'internal' => []], false);

        self::assertSame(['public', 'internal'], $routes->audiences());
        self::assertSame(['2024'], (new DocumentedRoutes(['2024' => []], false))->audiences());
        self::assertSame([$first, $second], $routes->in('public'));
        self::assertFalse($routes->operationIdsEnabled);
    }

    /**
     * Test that a later route owns a path and verb that keep their first
     * position.
     *
     * @return void
     */
    public function testLaterRouteOwnsTheOperationAndTheKeyKeepsItsPosition(): void
    {
        $a     = self::documentedRoute('/a', ['get', 'post']);
        $b     = self::documentedRoute('/b');
        $later = self::documentedRoute('/a');

        $operations = (new DocumentedRoutes(['public' => [$a, $b, $later]], false))->operations('public');

        self::assertSame(['/a', '/b'], array_keys($operations));
        self::assertSame(['get', 'post'], array_keys($operations['/a']));
        self::assertSame($later, $operations['/a']['get']);
        self::assertSame($a, $operations['/a']['post']);
        self::assertSame($b, $operations['/b']['get']);
    }

    /**
     * Test that the description keys cover each exported id and the primary id
     * of its route, even when the primary verb is shadowed.
     *
     * @return void
     */
    public function testDescriptionKeysIncludeAShadowedPrimary(): void
    {
        $update  = self::documentedRoute('/u', ['put', 'patch'], ['put' => 'u.update', 'patch' => 'u.update.patch']);
        $replace = self::documentedRoute('/u', ['put'], ['put' => 'u.replace']);
        $show    = self::documentedRoute('/u', ['get', 'post'], ['get' => 'u.show', 'post' => 'u.show.post']);

        $routes = new DocumentedRoutes(['public' => [$update, $replace, $show]], true);

        self::assertSame(['u.replace', 'u.update.patch', 'u.update', 'u.show', 'u.show.post'], $routes->descriptionKeys('public'));
    }

    /**
     * Test that the routes yield no description key while ids are off.
     *
     * @return void
     */
    public function testDescriptionKeysAreEmptyWhileIdsAreOff(): void
    {
        self::assertSame([], (new DocumentedRoutes(['public' => [self::documentedRoute('/a')]], false))->descriptionKeys('public'));
    }

    /**
     * Test that the tags are distinct and drop a fully shadowed route's tag.
     *
     * @return void
     */
    public function testTagsAreDistinctAndDropAFullyShadowedRoutesTag(): void
    {
        $routes = new DocumentedRoutes(['public' => [
            self::documentedRoute('/a', tag: 'Shadowed'),
            self::documentedRoute('/b', tag: 'User'),
            self::documentedRoute('/a', tag: 'Article'),
            self::documentedRoute('/c', tag: 'User'),
            self::documentedRoute('/d', tag: 'Widget'),
        ]], false);

        self::assertSame(['Article', 'User', 'Widget'], $routes->tags('public'));
    }

    /**
     * Test that an unknown audience has no routes, operations, keys or tags.
     *
     * @return void
     */
    public function testUnknownAudienceHasNothing(): void
    {
        $routes = new DocumentedRoutes(['public' => [self::documentedRoute('/a', operationIds: ['get' => 'a'])]], true);

        self::assertSame([], $routes->in('internal'));
        self::assertSame([], $routes->operations('internal'));
        self::assertSame([], $routes->descriptionKeys('internal'));
        self::assertSame([], $routes->tags('internal'));
    }
}
