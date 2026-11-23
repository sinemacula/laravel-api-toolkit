<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\BadRequestException;
use SineMacula\ApiToolkit\Exceptions\UnsupportedMediaTypeException;
use SineMacula\ApiToolkit\Http\Middleware\RequireJsonBody;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\TestCase;

/**
 * Tests for the RequireJsonBody middleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RequireJsonBody::class)]
final class RequireJsonBodyTest extends TestCase
{
    /**
     * Provide each write method with a JSON body that is not an object or
     * array.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableBodyProvider(): iterable
    {
        foreach (['POST', 'PUT', 'PATCH'] as $method) {
            yield $method . ' with invalid JSON' => [$method, '{title: "Draft"}'];
            yield $method . ' with truncated JSON' => [$method, '{"title": "Dr'];
            yield $method . ' with a string' => [$method, '"Draft"'];
            yield $method . ' with a number' => [$method, '42'];
            yield $method . ' with null' => [$method, 'null'];
        }
    }

    /**
     * Test that a JSON body that does not decode to an object or array is
     * refused as a bad request before it reaches the action.
     *
     * @param  string  $method
     * @param  string  $body
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\BadRequestException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnsupportedMediaTypeException
     */
    #[DataProvider('unreadableBodyProvider')]
    public function testUnreadableJsonBodyIsABadRequest(string $method, string $body): void
    {
        $this->expectException(BadRequestException::class);

        $this->handle($this->request($method, 'application/json', $body));
    }

    /**
     * Provide each write method with a body sent under a type other than JSON.
     *
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function otherMediaTypeProvider(): iterable
    {
        foreach (['POST', 'PUT', 'PATCH'] as $method) {
            yield $method . ' with text' => [$method, 'text/plain', '{"title": "Draft"}'];
            yield $method . ' without a type' => [$method, null, '{"title": "Draft"}'];
            yield $method . ' with a type merely containing json' => [$method, 'application/jsonp', '{"title": "Draft"}'];
        }
    }

    /**
     * Test that a body sent under a type other than JSON is refused as an
     * unsupported media type, even when it reads as JSON.
     *
     * @param  string  $method
     * @param  string|null  $contentType
     * @param  string  $body
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\BadRequestException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnsupportedMediaTypeException
     */
    #[DataProvider('otherMediaTypeProvider')]
    public function testBodyUnderAnotherMediaTypeIsUnsupported(string $method, ?string $contentType, string $body): void
    {
        $this->expectException(UnsupportedMediaTypeException::class);

        $this->handle($this->request($method, $contentType, $body));
    }

    /**
     * Test that a form-encoded body, which leaves the raw body empty once
     * parsed, is refused as an unsupported media type.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\BadRequestException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnsupportedMediaTypeException
     */
    public function testFormFieldIsUnsupported(): void
    {
        $this->expectException(UnsupportedMediaTypeException::class);

        $this->handle(Request::create('/documents', 'POST', ['title' => 'Draft']));
    }

    /**
     * Test that a multipart body carrying only a file is refused as an
     * unsupported media type.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\BadRequestException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnsupportedMediaTypeException
     */
    public function testUploadedFileIsUnsupported(): void
    {
        $this->expectException(UnsupportedMediaTypeException::class);

        $files = ['scan' => UploadedFile::fake()->create('scan.pdf')];

        $this->handle(Request::create('/documents', 'POST', files: $files, server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=x']));
    }

    /**
     * Provide requests the middleware passes through.
     *
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function passingProvider(): iterable
    {
        yield 'POST with an object' => ['POST', 'application/json', '{"title": "Draft"}'];
        yield 'PUT with an array' => ['PUT', 'application/json', '[1, 2]'];
        yield 'PATCH with an empty object' => ['PATCH', 'application/json', '{}'];
        yield 'POST with a charset' => ['POST', 'application/json; charset=utf-8', '{"title": "Draft"}'];
        yield 'POST with space before the parameters' => ['POST', 'application/json ; charset=utf-8', '{"title": "Draft"}'];
        yield 'POST with a +json type' => ['POST', 'application/vnd.api+json', '{"data": {}}'];
        yield 'POST with a mixed case type' => ['POST', 'Application/JSON', '{"title": "Draft"}'];
        yield 'POST with an empty body' => ['POST', null, ''];
        yield 'PATCH with a whitespace-only body' => ['PATCH', 'text/plain', " \t\r\n"];
        yield 'GET with a form type and invalid body' => ['GET', 'application/x-www-form-urlencoded', '{'];
        yield 'DELETE with invalid JSON' => ['DELETE', 'application/json', '{'];
    }

    /**
     * Test that a JSON object or array, a request without a body, and a read or
     * delete reach the action.
     *
     * @param  string  $method
     * @param  string|null  $contentType
     * @param  string  $body
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\BadRequestException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnsupportedMediaTypeException
     */
    #[DataProvider('passingProvider')]
    public function testOtherRequestsReachTheAction(string $method, ?string $contentType, string $body): void
    {
        self::assertSame('reached', $this->handle($this->request($method, $contentType, $body))->getContent());
    }

    /**
     * Test that a body under a JSON media type in any case reaches the action
     * readable as JSON, under the lowercased type and its parameters.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\BadRequestException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnsupportedMediaTypeException
     */
    public function testMixedCaseJsonBodyReachesTheActionReadable(): void
    {
        $response = (new RequireJsonBody)->handle(
            $this->request('PATCH', 'Application/Vnd.Api+JSON ; charset=UTF-8', '{"title": "Draft"}'),
            static fn (Request $request): Response => new Response($request->headers->get('Content-Type') . ' ' . $request->string('title')),
        );

        self::assertSame('application/vnd.api+json; charset=UTF-8 Draft', $response->getContent());
    }

    /**
     * Run the middleware in front of an action answering "reached".
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\BadRequestException
     * @throws \SineMacula\ApiToolkit\Exceptions\UnsupportedMediaTypeException
     */
    private function handle(Request $request): SymfonyResponse
    {
        return (new RequireJsonBody)->handle($request, static fn (): Response => new Response('reached'));
    }

    /**
     * Build a request with the given method, Content-Type and raw body.
     *
     * @param  string  $method
     * @param  string|null  $contentType
     * @param  string  $body
     * @return \Illuminate\Http\Request
     */
    private function request(string $method, ?string $contentType, string $body): Request
    {
        $request = Request::create('/documents/1', $method, content: $body);

        $request->headers->remove('Content-Type');

        if ($contentType !== null) {
            $request->headers->set('Content-Type', $contentType);
        }

        return $request;
    }
}
