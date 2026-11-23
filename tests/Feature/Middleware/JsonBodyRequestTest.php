<?php

declare(strict_types = 1);

namespace Tests\Feature\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\UnsupportedMediaTypeException;
use SineMacula\ApiToolkit\Http\Middleware\RequireJsonBody;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Input\StorePayload;
use Tests\TestCase;

/**
 * Feature tests for routes that refuse a body they cannot read as JSON.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RequireJsonBody::class)]
#[CoversClass(UnsupportedMediaTypeException::class)]
final class JsonBodyRequestTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up a create validating a required title and a modify, read and delete
     * echoing the input they read, behind the middleware.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(RequireJsonBody::class)->group(function (): void {

            $echo = static fn (Request $request): JsonResponse => new JsonResponse(['input' => $request->all()]);

            Route::post('/documents', static fn (Request $request): JsonResponse => new JsonResponse(['title' => StorePayload::from($request)->title], 201));
            Route::patch('/documents/{id}', $echo);
            Route::get('/documents/{id}', $echo);
            Route::delete('/documents/{id}', $echo);
        });
    }

    /**
     * Provide JSON bodies that do not decode to an object or array.
     *
     * @return iterable<string, array{string}>
     */
    public static function unreadableBodyProvider(): iterable
    {
        yield 'invalid' => ['{title: "Draft"}'];
        yield 'truncated' => ['{"title": "Dr'];
        yield 'a scalar' => ['"Draft"'];
        yield 'null' => ['null'];
    }

    /**
     * Test that an unreadable JSON body is a bad request, not one validation
     * error per required field, and the parser's message is not disclosed.
     *
     * @param  string  $body
     * @return void
     */
    #[DataProvider('unreadableBodyProvider')]
    public function testUnreadableBodyIsABadRequestWithoutValidationErrors(string $body): void
    {
        $response = $this->send('POST', '/documents', 'application/json', $body);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 10100)
            ->assertJsonPath('error.detail', 'There was an issue with the request, please try again')
            ->assertJsonMissingPath('error.meta');
    }

    /**
     * Test that an unreadable body on a modify whose fields are all optional is
     * refused rather than succeeding as a no-op.
     *
     * @return void
     */
    public function testUnreadableBodyOnAModifyIsNotANoOp(): void
    {
        $this->send('PATCH', '/documents/1', 'application/json', '{"title": "Dr')->assertStatus(400);
    }

    /**
     * Test that a form-encoded body is refused as an unsupported media type.
     *
     * @return void
     */
    public function testFormBodyIsUnsupported(): void
    {
        $this->post('/documents', ['title' => 'Draft'], ['Accept' => 'application/json'])
            ->assertStatus(415)
            ->assertJsonPath('error.code', 10116)
            ->assertJsonPath('error.title', 'Unsupported Media Type')
            ->assertJsonPath('error.detail', 'The request body is in a format this endpoint does not accept, please check its Content-Type');
    }

    /**
     * Test that a multipart body carrying a file is refused as an unsupported
     * media type.
     *
     * @return void
     */
    public function testMultipartFileIsUnsupported(): void
    {
        $this->call('POST', '/documents', [], [], ['scan' => UploadedFile::fake()->create('scan.pdf')], [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
            'HTTP_ACCEPT'  => 'application/json',
        ])->assertStatus(415)->assertJsonPath('error.code', 10116);
    }

    /**
     * Provide the JSON media types a body is read under.
     *
     * @return iterable<string, array{string}>
     */
    public static function jsonMediaTypeProvider(): iterable
    {
        yield 'application/json' => ['application/json'];
        yield 'with a charset' => ['application/json; charset=utf-8'];
        yield 'a +json type' => ['application/vnd.api+json'];
        yield 'mixed case' => ['Application/JSON'];
    }

    /**
     * Test that a JSON body under any JSON media type reaches the action, which
     * reads it parsed.
     *
     * @param  string  $contentType
     * @return void
     */
    #[DataProvider('jsonMediaTypeProvider')]
    public function testJsonBodyReachesTheActionParsed(string $contentType): void
    {
        $this->send('POST', '/documents', $contentType, '{"title": "Draft"}')
            ->assertCreated()
            ->assertExactJson(['title' => 'Draft']);
    }

    /**
     * Provide requests without a body, and reads and deletes with any body.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function passingRequestProvider(): iterable
    {
        yield 'an empty modify' => ['PATCH', 'text/plain', ''];
        yield 'a whitespace-only modify' => ['PATCH', 'text/plain', " \r\n\t"];
        yield 'a read' => ['GET', 'application/json', '{'];
        yield 'a delete' => ['DELETE', 'application/json', '{'];
    }

    /**
     * Test that a request without a body, a read and a delete pass through to
     * the action unchanged.
     *
     * @param  string  $method
     * @param  string  $contentType
     * @param  string  $body
     * @return void
     */
    #[DataProvider('passingRequestProvider')]
    public function testRequestWithoutABodyOrAReadPasses(string $method, string $contentType, string $body): void
    {
        $this->send($method, '/documents/1', $contentType, $body)->assertOk()->assertExactJson(['input' => []]);
    }

    /**
     * Send a request with a raw body under the given Content-Type.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  string  $contentType
     * @param  string  $body
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $method, string $uri, string $contentType, string $body): TestResponse
    {
        return $this->call($method, $uri, server: ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json'], content: $body);
    }
}
