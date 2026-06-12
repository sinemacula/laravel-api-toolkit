<?php

namespace Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\DetectsCapabilities;
use SineMacula\ApiToolkit\Http\RequestCapabilities;
use SineMacula\Http\Enums\HttpHeader;
use SineMacula\Http\Enums\MediaType;
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
class DetectsCapabilitiesTest extends TestCase
{
    /**
     * Test that the middleware stores capabilities on the request.
     *
     * @return void
     */
    public function testHandleStoresCapabilitiesOnRequest(): void
    {
        $middleware = new DetectsCapabilities;
        $request    = Request::create('/test', 'GET', ['include_trashed' => 'true']);

        $middleware->handle($request, function (Request $request): Response {

            $capabilities = $request->attributes->get(RequestCapabilities::class);

            static::assertInstanceOf(RequestCapabilities::class, $capabilities);

            return new Response;
        });
    }

    /**
     * Test that the middleware passes the request to the next handler
     * and returns the response.
     *
     * @return void
     */
    public function testHandlePassesRequestToNextMiddleware(): void
    {
        $middleware       = new DetectsCapabilities;
        $request          = Request::create('/test');
        $expectedResponse = new Response('OK');

        $response = $middleware->handle($request, fn (): Response => $expectedResponse);

        static::assertSame($expectedResponse, $response);
    }

    /**
     * Test that the stored capabilities reflect the request's query
     * parameters and headers.
     *
     * @return void
     */
    public function testHandleResolvesCapabilitiesFromRequest(): void
    {
        config()->set('api-toolkit.exports.enabled', true);
        config()->set('api-toolkit.exports.supported_formats', ['csv', 'xml']);

        $middleware = new DetectsCapabilities;

        $request = Request::create('/test', 'GET', ['include_trashed' => 'true']);
        $request->headers->set(HttpHeader::ACCEPT->getName(), MediaType::TEXT_CSV->getMimeType());

        $middleware->handle($request, function (Request $request): Response {

            $capabilities = RequestCapabilities::fromRequest($request);

            static::assertTrue($capabilities->includeTrashed());
            static::assertTrue($capabilities->expectsCsv());
            static::assertTrue($capabilities->expectsExport());
            static::assertFalse($capabilities->onlyTrashed());
            static::assertFalse($capabilities->expectsXml());
            static::assertFalse($capabilities->expectsPdf());
            static::assertFalse($capabilities->expectsStream());

            return new Response;
        });
    }
}
