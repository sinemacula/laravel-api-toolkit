<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Middleware\Traits;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests as BaseThrottleRequests;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Exceptions\RequestSignatureException;
use SineMacula\ApiToolkit\Http\Middleware\Concerns\ThrottleRequestsTrait;
use SineMacula\Http\Enums\HttpMethod;

/**
 * Tests for the ThrottleRequestsTrait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(ThrottleRequestsTrait::class)]
final class ThrottleRequestsTraitTest extends TestCase
{
    /** @var string The request URI used to drive the throttling tests. */
    private const string API_DATA_URI = '/api/data';

    /**
     * Test that resolveRequestSignature throws RequestSignatureException when
     * route is null.
     *
     * @return void
     */
    public function testThrowsRequestSignatureExceptionWhenRouteIsNull(): void
    {
        $this->expectException(RequestSignatureException::class);
        $this->expectExceptionMessage('Unable to generate the request signature. Route unavailable.');

        $trait   = $this->createTraitInstance();
        $request = Request::create('/test', HttpMethod::GET->getVerb());

        $trait->resolveRequestSignature($request); // @phpstan-ignore method.notFound
    }

    /**
     * Test that resolveRequestSignature returns a SHA1 hash.
     *
     * @return void
     */
    public function testReturnsSha1Hash(): void
    {
        $trait   = $this->createTraitInstance();
        $request = $this->createRequestWithRoute('/test', HttpMethod::GET->getVerb());

        $result = $trait->resolveRequestSignature($request); // @phpstan-ignore method.notFound

        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $result);
    }

    /**
     * Test that the signature uses user ID when authenticated.
     *
     * @return void
     */
    public function testSignatureUsesUserIdWhenAuthenticated(): void
    {
        $trait = $this->createTraitInstance();

        $user = self::createStub(Authenticatable::class);
        $user->method('getAuthIdentifier')->willReturn(42);

        $request = $this->createRequestWithRoute(self::API_DATA_URI, HttpMethod::GET->getVerb(), '10.0.0.1');
        $request->setUserResolver(fn () => $user);

        $result   = $trait->resolveRequestSignature($request); // @phpstan-ignore method.notFound
        $expected = sha1('GET|localhost|api/data|42');

        self::assertSame($expected, $result);
    }

    /**
     * Test that the signature handles unauthenticated users.
     *
     * An unauthenticated request is keyed by its client IP, so anonymous
     * callers each get their own throttle bucket instead of sharing one.
     *
     * @return void
     */
    public function testSignatureHandlesUnauthenticatedUsers(): void
    {
        $trait   = $this->createTraitInstance();
        $request = $this->createRequestWithRoute(self::API_DATA_URI, HttpMethod::GET->getVerb(), '192.168.1.50');

        $result   = $trait->resolveRequestSignature($request); // @phpstan-ignore method.notFound
        $expected = sha1('GET|localhost|api/data|192.168.1.50');

        self::assertSame($expected, $result);
    }

    /**
     * Test that two unauthenticated requests to the same endpoint from
     * different client IPs produce different signatures, so one anonymous
     * caller cannot exhaust the shared throttle bucket for everyone.
     *
     * @return void
     */
    public function testSignatureDiffersByClientIpForUnauthenticatedRequests(): void
    {
        $trait = $this->createTraitInstance();

        $first  = $this->createRequestWithRoute(self::API_DATA_URI, HttpMethod::GET->getVerb(), '203.0.113.1');
        $second = $this->createRequestWithRoute(self::API_DATA_URI, HttpMethod::GET->getVerb(), '203.0.113.2');

        $firstSignature  = $trait->resolveRequestSignature($first);  // @phpstan-ignore method.notFound
        $secondSignature = $trait->resolveRequestSignature($second); // @phpstan-ignore method.notFound

        self::assertNotSame($firstSignature, $secondSignature);
    }

    /**
     * Test that different methods produce different signatures.
     *
     * @return void
     */
    public function testDifferentMethodsProduceDifferentSignatures(): void
    {
        $trait = $this->createTraitInstance();

        $getRequest  = $this->createRequestWithRoute(self::API_DATA_URI, HttpMethod::GET->getVerb());
        $postRequest = $this->createRequestWithRoute(self::API_DATA_URI, HttpMethod::POST->getVerb());

        $getSignature  = $trait->resolveRequestSignature($getRequest); // @phpstan-ignore method.notFound
        $postSignature = $trait->resolveRequestSignature($postRequest); // @phpstan-ignore method.notFound

        self::assertNotSame($getSignature, $postSignature);
    }

    /**
     * Test that different paths produce different signatures.
     *
     * @return void
     */
    public function testDifferentPathsProduceDifferentSignatures(): void
    {
        $trait = $this->createTraitInstance();

        $request1 = $this->createRequestWithRoute('/api/users', HttpMethod::GET->getVerb());
        $request2 = $this->createRequestWithRoute('/api/posts', HttpMethod::GET->getVerb());

        $signature1 = $trait->resolveRequestSignature($request1); // @phpstan-ignore method.notFound
        $signature2 = $trait->resolveRequestSignature($request2); // @phpstan-ignore method.notFound

        self::assertNotSame($signature1, $signature2);
    }

    /**
     * Test that resolveRequestSignature is callable from a middleware subclass,
     * asserting the trait override remains compatible with the protected hook
     * declared by Laravel's base ThrottleRequests middleware.
     *
     * @return void
     */
    public function testResolveRequestSignatureIsCallableFromMiddlewareSubclass(): void
    {
        $cache   = self::createStub(Repository::class);
        $limiter = new RateLimiter($cache);

        $middleware = new class ($limiter) extends BaseThrottleRequests {
            use ThrottleRequestsTrait;

            /**
             * @param  \Illuminate\Http\Request  $request
             * @return string
             */
            public function callResolveRequestSignature(Request $request): string
            {
                return $this->resolveRequestSignature($request);
            }
        };

        $request = $this->createRequestWithRoute(self::API_DATA_URI, HttpMethod::GET->getVerb());

        $result = $middleware->callResolveRequestSignature($request);

        self::assertSame(sha1('GET|localhost|api/data|127.0.0.1'), $result);
    }

    /**
     * Create an anonymous class instance that uses the trait.
     *
     * @return object
     */
    private function createTraitInstance(): object
    {
        return new class {
            use ThrottleRequestsTrait {
                resolveRequestSignature as public;
            }
        };
    }

    /**
     * Create a request with a route attached.
     *
     * @param  string  $uri
     * @param  string  $method
     * @param  string  $ip
     * @return \Illuminate\Http\Request
     */
    private function createRequestWithRoute(string $uri, string $method, string $ip = '127.0.0.1'): Request
    {
        $request = Request::create($uri, $method, [], [], [], ['REMOTE_ADDR' => $ip]);
        $route   = new Route($method, $uri, static function (): void {
            // Route action placeholder
        });
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}
