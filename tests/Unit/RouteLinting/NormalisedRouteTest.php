<?php

namespace Tests\Unit\RouteLinting;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use Tests\TestCase;

/**
 * Tests for NormalisedRoute::identity().
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NormalisedRoute::class)]
class NormalisedRouteTest extends TestCase
{
    /**
     * Test that methods are sorted ascending regardless of construction order.
     *
     * @return void
     */
    public function testMethodsAreSortedDeterministically(): void
    {
        $route = new NormalisedRoute(
            uri: 'users',
            methods: ['POST', 'GET'],
            name: null,
            segments: ['users'],
            parameters: [],
        );

        static::assertStringStartsWith('GET,POST ', $route->identity());
    }

    /**
     * Test that the URI is included in the identity string.
     *
     * @return void
     */
    public function testUriIsIncludedInIdentity(): void
    {
        $route = new NormalisedRoute(
            uri: 'users/{user}',
            methods: ['GET'],
            name: null,
            segments: ['users', '{user}'],
            parameters: ['user'],
        );

        static::assertSame('GET users/{user}', $route->identity());
    }

    /**
     * Test that a named route appends the name after the URI.
     *
     * @return void
     */
    public function testNamedRouteAppendsNameToIdentity(): void
    {
        $route = new NormalisedRoute(
            uri: 'users',
            methods: ['HEAD', 'GET'],
            name: 'users.index',
            segments: ['users'],
            parameters: [],
        );

        static::assertSame('GET,HEAD users users.index', $route->identity());
    }

    /**
     * Test that an unnamed route omits the name from the identity.
     *
     * @return void
     */
    public function testUnnamedRouteOmitsNameFromIdentity(): void
    {
        $route = new NormalisedRoute(
            uri: 'orders',
            methods: ['GET'],
            name: null,
            segments: ['orders'],
            parameters: [],
        );

        static::assertSame('GET orders', $route->identity());
    }
}
