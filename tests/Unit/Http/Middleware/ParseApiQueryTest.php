<?php

namespace Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use Tests\TestCase;

/**
 * Tests for the ParseApiQuery middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ParseApiQuery::class)]
class ParseApiQueryTest extends TestCase
{
    /**
     * Test that the middleware calls ApiQuery::parse and passes request to next.
     *
     * @return void
     */
    public function testMiddlewareCallsParseAndPassesRequestToNext(): void
    {
        ApiQuery::shouldReceive('parse')
            ->once();

        $request          = Request::create('/test', 'GET');
        $middleware       = new ParseApiQuery;
        $expectedResponse = new Response('ok');
        $receivedRequest  = null;

        $result = $middleware->handle($request, function ($req) use (&$receivedRequest, $expectedResponse) {
            $receivedRequest = $req;

            return $expectedResponse;
        });

        static::assertSame($request, $receivedRequest);
        static::assertSame($expectedResponse, $result);
    }

    /**
     * Test that the response from the next handler is returned.
     *
     * @return void
     */
    public function testResponseIsReturnedFromNextHandler(): void
    {
        ApiQuery::shouldReceive('parse')
            ->once();

        $request          = Request::create('/test', 'GET');
        $middleware       = new ParseApiQuery;
        $expectedResponse = new Response('expected content', 201);

        $result = $middleware->handle($request, fn () => $expectedResponse);

        static::assertSame($expectedResponse, $result);
        static::assertSame(201, $result->getStatusCode());
    }
}
