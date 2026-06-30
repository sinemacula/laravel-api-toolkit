<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\DetectsCapabilities;
use SineMacula\ApiToolkit\Http\RequestCapabilities;
use Tests\TestCase;

/**
 * Unit tests for the DetectsCapabilities middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DetectsCapabilities::class)]
final class DetectsCapabilitiesTest extends TestCase
{
    /** @var string */
    private const string TEST_URL = '/test';

    /**
     * Test that the middleware stores capabilities on the request.
     *
     * @return void
     */
    public function testHandleStoresCapabilitiesOnRequest(): void
    {
        $middleware = new DetectsCapabilities;
        $request    = Request::create(self::TEST_URL, 'GET', ['include_trashed' => 'true']);

        $middleware->handle($request, function (Request $request): Response {

            $capabilities = $request->attributes->get(RequestCapabilities::class);

            static::assertInstanceOf(RequestCapabilities::class, $capabilities);

            return new Response;
        });
    }

    /**
     * Test that the middleware passes the request to the next handler and
     * returns the response.
     *
     * @return void
     */
    public function testHandlePassesRequestToNextMiddleware(): void
    {
        $middleware       = new DetectsCapabilities;
        $request          = Request::create(self::TEST_URL);
        $expectedResponse = new Response('OK');

        $response = $middleware->handle($request, fn (): Response => $expectedResponse);

        self::assertSame($expectedResponse, $response);
    }

    /**
     * Test that the stored capabilities reflect the request's query parameters
     * and headers.
     *
     * @return void
     */
    public function testHandleResolvesCapabilitiesFromRequest(): void
    {
        $middleware = new DetectsCapabilities;

        $request = Request::create(self::TEST_URL, 'GET', ['include_trashed' => 'true']);

        $middleware->handle($request, function (Request $request): Response {

            $capabilities = RequestCapabilities::fromRequest($request);

            static::assertTrue($capabilities->includeTrashed());
            static::assertFalse($capabilities->onlyTrashed());
            static::assertFalse($capabilities->expectsPdf());

            return new Response;
        });
    }
}
