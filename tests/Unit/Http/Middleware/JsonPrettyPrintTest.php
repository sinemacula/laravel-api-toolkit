<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use SineMacula\Http\Enums\HttpMethod;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tests for the JsonPrettyPrint middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(JsonPrettyPrint::class)]
final class JsonPrettyPrintTest extends TestCase
{
    /** @var string The shared test URI. */
    private const string TEST_URI = '/test';

    /** @var string The JSON content type header value. */
    private const string CONTENT_TYPE_JSON = 'application/json';

    /**
     * Test that response content is unchanged without the pretty parameter.
     *
     * @return void
     */
    public function testResponseUnchangedWithoutPrettyParam(): void
    {
        $json       = json_encode(['key' => 'value']);
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb());
        $middleware = new JsonPrettyPrint;

        $response = $middleware->handle($request, fn () => new Response($json));

        self::assertSame($json, $response->getContent());
    }

    /**
     * Test that JsonResponse content is pretty-printed with pretty=true.
     *
     * @return void
     */
    public function testResponseIsPrettyPrintedWithPrettyParam(): void
    {
        $payload    = ['key' => 'value', 'nested' => ['a' => 1]];
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;

        $response = $middleware->handle($request, fn () => new JsonResponse($payload));

        $expected = json_encode($payload, JSON_PRETTY_PRINT);

        self::assertSame($expected, $response->getContent());
    }

    /**
     * Test that pretty=false does not pretty-print the response.
     *
     * @return void
     */
    public function testResponseUnchangedWithPrettyFalse(): void
    {
        $json       = json_encode(['key' => 'value']);
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'false']);
        $middleware = new JsonPrettyPrint;

        $response = $middleware->handle($request, fn () => new Response($json));

        self::assertSame($json, $response->getContent());
    }

    /**
     * Test that non-JSON content passes through unmodified with pretty=true.
     *
     * @return void
     */
    public function testHandlesNonJsonContentWithPrettyParam(): void
    {
        $content    = 'This is not JSON';
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;

        $response = $middleware->handle($request, fn () => new Response($content));

        self::assertSame('This is not JSON', $response->getContent());
    }

    /**
     * Test that the response object from next is returned.
     *
     * @return void
     */
    public function testReturnsResponseFromNext(): void
    {
        $request          = Request::create(self::TEST_URI, HttpMethod::GET->getVerb());
        $middleware       = new JsonPrettyPrint;
        $expectedResponse = new Response('ok');

        $response = $middleware->handle($request, fn () => $expectedResponse);

        self::assertSame($expectedResponse, $response);
    }

    /**
     * Test that existing encoding options are preserved on JsonResponse.
     *
     * @return void
     */
    public function testPreservesExistingEncodingOptionsOnJsonResponse(): void
    {
        $payload    = ['url' => 'https://example.com/path'];
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;

        $jsonResponse = new JsonResponse($payload, 200, [], 0);
        $jsonResponse->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        $response = $middleware->handle($request, fn () => $jsonResponse);

        self::assertInstanceOf(JsonResponse::class, $response);

        $expectedOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG;
        $content         = $response->getContent();

        self::assertSame($expectedOptions, $response->getEncodingOptions());
        self::assertIsString($content);
        self::assertStringContainsString('https://example.com/path', $content);
    }

    /**
     * Test that already pretty-printed JsonResponse produces identical output.
     *
     * @return void
     */
    public function testIdempotentOnAlreadyPrettyPrintedJsonResponse(): void
    {
        $payload    = ['key' => 'value'];
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;

        $jsonResponse = new JsonResponse($payload, 200, [], 0);
        $jsonResponse->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $contentBefore = $jsonResponse->getContent();

        $response = $middleware->handle($request, fn () => $jsonResponse);

        self::assertSame($contentBefore, $response->getContent());
    }

    /**
     * Test that StreamedResponse is returned unmodified.
     *
     * @return void
     */
    public function testSkipsStreamedResponse(): void
    {
        $request          = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware       = new JsonPrettyPrint;
        $streamedResponse = new StreamedResponse(function (): void {
            echo 'streamed';
        });

        $response = $middleware->handle($request, fn () => $streamedResponse);

        self::assertSame($streamedResponse, $response);
    }

    /**
     * Test that BinaryFileResponse is returned unmodified.
     *
     * @return void
     */
    public function testSkipsBinaryFileResponse(): void
    {
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;
        $tempFile   = tempnam(sys_get_temp_dir(), 'test');

        self::assertIsString($tempFile);

        $binaryFileResponse = new BinaryFileResponse($tempFile);

        $response = $middleware->handle($request, fn () => $binaryFileResponse);

        self::assertSame($binaryFileResponse, $response);

        unlink($tempFile);
    }

    /**
     * Test that plain Response with JSON content-type is pretty-printed.
     *
     * @return void
     */
    public function testPrettyPrintsPlainResponseWithJsonContentType(): void
    {
        $payload    = json_encode(['key' => 'value']);
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;

        $plainResponse = new Response($payload, 200, ['Content-Type' => self::CONTENT_TYPE_JSON]);

        $response = $middleware->handle($request, fn () => $plainResponse);

        $expected = json_encode(['key' => 'value'], JSON_PRETTY_PRINT);

        self::assertSame($expected, $response->getContent());
    }

    /**
     * Test that a non-streamed response whose content is not a string (its
     * body is served from a file) is left untouched even when it declares a
     * JSON content type.
     *
     * @return void
     */
    public function testSkipsPlainResponseWhoseContentIsNotAString(): void
    {
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;
        $tempFile   = tempnam(sys_get_temp_dir(), 'test');

        self::assertIsString($tempFile);

        $binaryFileResponse = new BinaryFileResponse($tempFile);
        $binaryFileResponse->headers->set('Content-Type', self::CONTENT_TYPE_JSON);

        $response = $middleware->handle($request, fn () => $binaryFileResponse);

        self::assertSame($binaryFileResponse, $response);
        self::assertFalse($response->getContent());

        unlink($tempFile);
    }

    /**
     * Test that plain Response with JSON content-type preserves malformed JSON.
     *
     * @return void
     */
    public function testPreservesPlainResponseWithJsonContentTypeOnDecodeFailure(): void
    {
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;

        $plainResponse = new Response('not valid json', 200, ['Content-Type' => self::CONTENT_TYPE_JSON]);

        $response = $middleware->handle($request, fn () => $plainResponse);

        self::assertSame('not valid json', $response->getContent());
    }

    /**
     * Test that a plain Response whose body is valid JSON but whose
     * content-type is not JSON is left untouched, so only responses that
     * declare themselves as JSON are reformatted.
     *
     * @return void
     */
    public function testSkipsPlainResponseWithNonJsonContentTypeEvenWhenBodyIsJson(): void
    {
        $compact    = json_encode(['key' => 'value']);
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;

        $plainResponse = new Response($compact, 200, ['Content-Type' => 'text/plain']);

        $response = $middleware->handle($request, fn () => $plainResponse);

        self::assertSame($compact, $response->getContent());
    }

    /**
     * Test that plain Response with JSON content-type and literal JSON null
     * content is preserved.
     *
     * @return void
     */
    public function testHandlesLiteralJsonNullWithJsonContentType(): void
    {
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;

        $plainResponse = new Response('null', 200, ['Content-Type' => self::CONTENT_TYPE_JSON]);

        $response = $middleware->handle($request, fn () => $plainResponse);

        self::assertSame('null', $response->getContent());
    }

    /**
     * Test that a JsonResponse stays compact when the pretty parameter is
     * absent. Pretty printing must require an explicit opt-in.
     *
     * @return void
     */
    public function testJsonResponseStaysCompactWithoutPrettyParam(): void
    {
        $payload    = ['key' => 'value', 'nested' => ['a' => 1]];
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb());
        $middleware = new JsonPrettyPrint;

        $response = $middleware->handle($request, fn () => new JsonResponse($payload));

        self::assertSame(json_encode($payload), $response->getContent());
    }

    /**
     * Test that a plain response without a Content-Type header is handled
     * without raising a deprecation for a null header value.
     *
     * @return void
     */
    public function testHandlesMissingContentTypeHeaderWithoutDeprecation(): void
    {
        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;

        set_error_handler(static function (int $_severity, string $message): bool {
            throw new \ErrorException($message);
        }, E_DEPRECATED | E_USER_DEPRECATED);

        try {
            $response = $middleware->handle($request, fn () => new Response('plain text'));
        } finally {
            restore_error_handler();
        }

        self::assertSame('plain text', $response->getContent());
    }

    /**
     * Test that deeply nested JsonResponse data is correctly pretty-printed.
     *
     * @return void
     */
    public function testPrettyPrintsJsonResponseWithNestedData(): void
    {
        $payload = [
            'level1' => [
                'level2' => [
                    'level3' => ['key' => 'value'],
                ],
            ],
        ];

        $request    = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['pretty' => 'true']);
        $middleware = new JsonPrettyPrint;

        $response = $middleware->handle($request, fn () => new JsonResponse($payload));

        $expected = json_encode($payload, JSON_PRETTY_PRINT);

        self::assertSame($expected, $response->getContent());
    }
}
