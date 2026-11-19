<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Middleware;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use SineMacula\Http\Enums\HttpMethod;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests for the ThrottleRequests middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ThrottleRequests::class)]
final class ThrottleRequestsTest extends TestCase
{
    /**
     * Test that a request beyond the limit of a route pattern is refused,
     * whichever URL of the pattern it names.
     *
     * @return void
     *
     * @throws \Illuminate\Routing\Exceptions\MissingRateLimiterException
     */
    public function testRequestBeyondTheLimitOfARoutePatternIsRefused(): void
    {
        $middleware = self::middleware();

        $response = $middleware->handle(self::request('users/{user}', '/users/1'), self::next(), 1);

        self::assertSame('0', $response->headers->get('X-RateLimit-Remaining'));

        $this->expectException(ThrottleRequestsException::class);

        $middleware->handle(self::request('users/{user}', '/users/2'), self::next(), 1);
    }

    /**
     * Test that each route pattern keeps its own budget for one client.
     *
     * @return void
     *
     * @throws \Illuminate\Routing\Exceptions\MissingRateLimiterException
     */
    public function testEachRoutePatternKeepsItsOwnBudget(): void
    {
        $middleware = self::middleware();

        $middleware->handle(self::request('users/{user}', '/users/1'), self::next(), 1);

        $response = $middleware->handle(self::request('posts', '/posts'), self::next(), 1);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Build the middleware over an in-memory rate limiter.
     *
     * @return \SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests
     */
    private static function middleware(): ThrottleRequests
    {
        return new ThrottleRequests(new RateLimiter(new Repository(new ArrayStore)));
    }

    /**
     * Build a GET request for the URL, matched to the route pattern.
     *
     * @param  string  $pattern
     * @param  string  $url
     * @return \Illuminate\Http\Request
     */
    private static function request(string $pattern, string $url): Request
    {
        $request = Request::create($url, HttpMethod::GET->getVerb());
        $route   = (new Route([HttpMethod::GET->getVerb()], $pattern, []))->bind($request);

        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    }

    /**
     * Return the next handler, answering the request the middleware lets
     * through.
     *
     * @return \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response
     */
    private static function next(): \Closure
    {
        return static fn (): Response => new Response('ok');
    }
}
