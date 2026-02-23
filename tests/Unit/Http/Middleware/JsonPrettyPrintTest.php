<?php

namespace Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;

/**
 * Tests for the JsonPrettyPrint middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(JsonPrettyPrint::class)]
class JsonPrettyPrintTest extends TestCase
{
    private const string TEST_URI = '/test';

    /**
     * Test that response content is unchanged without the pretty parameter.
     *
     * @return void
     */
    public function testResponseUnchangedWithoutPrettyParam(): void
    {
        $data       = ['key' => 'value'];
        $json       = json_encode($data);
        $request    = Request::create(self::TEST_URI, 'GET');
        $middleware = new JsonPrettyPrint;

        $response = $middleware->handle($request, fn () => new Response($json));

        static::assertSame($json, $response->getContent());
    }

    /**
     * Test that response content is pretty-printed with pretty=true.
     *
     * @return void
     */
    public function testResponseIsPrettyPrintedWithPrettyParam(): void
    {
        $data       = ['key' => 'value', 'nested' => ['a' => 1]];
        $json       = json_encode($data);
        $request    = Request::create(self::TEST_URI, 'GET', ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;

        $response = $middleware->handle($request, fn () => new Response($json));

        $expected = json_encode($data, JSON_PRETTY_PRINT);

        static::assertSame($expected, $response->getContent());
    }

    /**
     * Test that pretty=false does not pretty-print the response.
     *
     * @return void
     */
    public function testResponseUnchangedWithPrettyFalse(): void
    {
        $data       = ['key' => 'value'];
        $json       = json_encode($data);
        $request    = Request::create(self::TEST_URI, 'GET', ['pretty' => 'false']);
        $middleware = new JsonPrettyPrint;

        $response = $middleware->handle($request, fn () => new Response($json));

        static::assertSame($json, $response->getContent());
    }

    /**
     * Test that non-JSON content is handled with pretty=true.
     *
     * @return void
     */
    public function testHandlesNonJsonContentWithPrettyParam(): void
    {
        $content    = 'This is not JSON';
        $request    = Request::create(self::TEST_URI, 'GET', ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;

        $response = $middleware->handle($request, fn () => new Response($content));

        // json_decode returns null for non-JSON, json_encode(null) returns 'null'
        static::assertSame('null', $response->getContent());
    }

    /**
     * Test that the response object from next is returned.
     *
     * @return void
     */
    public function testReturnsResponseFromNext(): void
    {
        $request          = Request::create(self::TEST_URI, 'GET');
        $middleware       = new JsonPrettyPrint;
        $expectedResponse = new Response('ok');

        $result = $middleware->handle($request, fn () => $expectedResponse);

        static::assertSame($expectedResponse, $result);
    }
}
