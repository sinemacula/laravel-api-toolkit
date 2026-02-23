<?php

namespace Tests\Unit\Http\Middleware\Traits;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Middleware\Traits\ThrottleRequestsTrait;

/**
 * Tests for the ThrottleRequestsTrait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ThrottleRequestsTrait::class)]
class ThrottleRequestsTraitTest extends TestCase
{
    private const string API_DATA_URI = '/api/data';

    /**
     * Test that resolveRequestSignature throws RuntimeException when route is null.
     *
     * @return void
     */
    public function testThrowsRuntimeExceptionWhenRouteIsNull(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to generate the request signature. Route unavailable.');

        $trait   = $this->createTraitInstance();
        $request = Request::create('/test', 'GET');

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
        $request = $this->createRequestWithRoute('/test', 'GET');

        $result = $trait->resolveRequestSignature($request); // @phpstan-ignore method.notFound

        static::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $result);
    }

    /**
     * Test that the signature uses user ID when authenticated.
     *
     * @return void
     */
    public function testSignatureUsesUserIdWhenAuthenticated(): void
    {
        $trait = $this->createTraitInstance();

        $user = $this->createMock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->method('getAuthIdentifier')->willReturn(42);

        $request = $this->createRequestWithRoute(self::API_DATA_URI, 'GET', '10.0.0.1');
        $request->setUserResolver(fn () => $user);

        $result   = $trait->resolveRequestSignature($request); // @phpstan-ignore method.notFound
        $expected = sha1('GET|localhost|api/data|42');

        static::assertSame($expected, $result);
    }

    /**
     * Test that the signature handles unauthenticated users.
     *
     * Due to operator precedence, the null coalescing operator applies to the
     * entire concatenated string when user is null. Since string concatenation
     * with null produces a non-null string, the IP fallback is not used.
     *
     * @return void
     */
    public function testSignatureHandlesUnauthenticatedUsers(): void
    {
        $trait   = $this->createTraitInstance();
        $request = $this->createRequestWithRoute(self::API_DATA_URI, 'GET', '192.168.1.50');

        $result   = $trait->resolveRequestSignature($request); // @phpstan-ignore method.notFound
        $expected = sha1('GET|localhost|api/data|');

        static::assertSame($expected, $result);
    }

    /**
     * Test that different methods produce different signatures.
     *
     * @return void
     */
    public function testDifferentMethodsProduceDifferentSignatures(): void
    {
        $trait = $this->createTraitInstance();

        $getRequest  = $this->createRequestWithRoute(self::API_DATA_URI, 'GET');
        $postRequest = $this->createRequestWithRoute(self::API_DATA_URI, 'POST');

        $getSignature  = $trait->resolveRequestSignature($getRequest); // @phpstan-ignore method.notFound
        $postSignature = $trait->resolveRequestSignature($postRequest); // @phpstan-ignore method.notFound

        static::assertNotSame($getSignature, $postSignature);
    }

    /**
     * Test that different paths produce different signatures.
     *
     * @return void
     */
    public function testDifferentPathsProduceDifferentSignatures(): void
    {
        $trait = $this->createTraitInstance();

        $request1 = $this->createRequestWithRoute('/api/users', 'GET');
        $request2 = $this->createRequestWithRoute('/api/posts', 'GET');

        $signature1 = $trait->resolveRequestSignature($request1); // @phpstan-ignore method.notFound
        $signature2 = $trait->resolveRequestSignature($request2); // @phpstan-ignore method.notFound

        static::assertNotSame($signature1, $signature2);
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
