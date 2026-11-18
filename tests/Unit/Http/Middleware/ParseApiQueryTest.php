<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\Http\Enums\HttpMethod;
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
final class ParseApiQueryTest extends TestCase
{
    /**
     * Test that the middleware calls ApiQuery::parse and passes request to
     * next.
     *
     * @return void
     */
    public function testMiddlewareCallsParseAndPassesRequestToNext(): void
    {
        ApiQuery::shouldReceive('parse')
            ->once();
        ApiQuery::shouldReceive('token')
            ->andReturn(new \stdClass);

        $request          = Request::create('/test', HttpMethod::GET->getVerb());
        $middleware       = new ParseApiQuery;
        $expectedResponse = new Response('ok');
        $receivedRequest  = null;

        $result = $middleware->handle($request, function ($req) use (&$receivedRequest, $expectedResponse): Response {
            $receivedRequest = $req;

            return $expectedResponse;
        });

        self::assertSame($request, $receivedRequest);
        self::assertSame($expectedResponse, $result);
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
        ApiQuery::shouldReceive('token')
            ->andReturn(new \stdClass);

        $request          = Request::create('/test', HttpMethod::GET->getVerb());
        $middleware       = new ParseApiQuery;
        $expectedResponse = new Response('expected content', 201);

        $result = $middleware->handle($request, fn () => $expectedResponse);

        self::assertSame($expectedResponse, $result);
        self::assertSame(201, $result->getStatusCode());
    }

    /**
     * Test that a request passing the middleware twice is parsed once.
     *
     * @return void
     */
    public function testRequestIsParsedOnce(): void
    {
        $request = Request::create('/test', HttpMethod::GET->getVerb());

        $this->handle($request);

        $token = ApiQuery::token();

        $this->handle($request);

        self::assertNotNull($token);
        self::assertSame($token, ApiQuery::token());
    }

    /**
     * Provide the requests a parsed request is followed by, each of which must
     * be parsed afresh.
     *
     * @return iterable<string, array{\Closure(\Illuminate\Http\Request): \Illuminate\Http\Request}>
     */
    public static function reparsedRequestProvider(): iterable
    {
        yield 'another request' => [static fn (): Request => Request::create('/test', HttpMethod::GET->getVerb())];
        yield 'a copy of the request' => [static fn (Request $request): Request => $request->duplicate()];
        yield 'the request after a reset' => [static function (Request $request): Request {
            ApiQuery::reset();

            return $request;
        }];
    }

    /**
     * Test that a request whose query is not the current one is parsed again.
     *
     * @param  \Closure(\Illuminate\Http\Request): \Illuminate\Http\Request  $next
     * @return void
     */
    #[DataProvider('reparsedRequestProvider')]
    public function testRequestIsParsedWhenItsQueryIsNotCurrent(\Closure $next): void
    {
        $request = Request::create('/test', HttpMethod::GET->getVerb());

        $this->handle($request);

        $token = ApiQuery::token();

        $this->handle($next($request));

        self::assertNotNull(ApiQuery::token());
        self::assertNotSame($token, ApiQuery::token());
    }

    /**
     * Pass the request through the middleware.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    private function handle(Request $request): void
    {
        (new ParseApiQuery)->handle($request, static fn (): Response => new Response('ok'));
    }
}
