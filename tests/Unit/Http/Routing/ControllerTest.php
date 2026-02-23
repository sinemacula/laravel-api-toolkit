<?php

namespace Tests\Unit\Http\Routing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use SineMacula\ApiToolkit\Http\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fixtures\Controllers\TestingController;
use Tests\TestCase;

/**
 * Tests for the base Controller.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Controller::class)]
class ControllerTest extends TestCase
{
    /** @var \Tests\Fixtures\Controllers\TestingController */
    private TestingController $controller;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new TestingController;
    }

    /**
     * Test that respondWithData returns a JsonResponse with data wrapper.
     *
     * @return void
     */
    public function testRespondWithDataReturnsJsonResponseWithDataWrapper(): void
    {
        $data = ['name' => 'Test', 'value' => 42];

        $response = $this->invokeProtected('respondWithData', [$data]);

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);

        static::assertArrayHasKey('data', $content);
        static::assertSame($data, $content['data']);
    }

    /**
     * Test that respondWithData accepts a custom status code.
     *
     * @return void
     */
    public function testRespondWithDataAcceptsCustomStatus(): void
    {
        $data = ['created' => true];

        $response = $this->invokeProtected('respondWithData', [$data, HttpStatus::CREATED]);

        static::assertSame(201, $response->getStatusCode());
    }

    /**
     * Test that respondWithData accepts custom headers.
     *
     * @return void
     */
    public function testRespondWithDataAcceptsCustomHeaders(): void
    {
        $data    = ['ok' => true];
        $headers = ['X-Custom-Header' => 'custom-value'];

        $response = $this->invokeProtected('respondWithData', [$data, HttpStatus::OK, $headers]);

        static::assertSame('custom-value', $response->headers->get('X-Custom-Header'));
    }

    /**
     * Test that respondWithItem returns a resource response.
     *
     * @return void
     */
    public function testRespondWithItemReturnsResourceResponse(): void
    {
        $resource = new JsonResource(['id' => 1, 'name' => 'Test']);

        $response = $this->invokeProtected('respondWithItem', [$resource]);

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(200, $response->getStatusCode());
    }

    /**
     * Test that respondWithItem accepts a custom status.
     *
     * @return void
     */
    public function testRespondWithItemAcceptsCustomStatus(): void
    {
        $resource = new JsonResource(['id' => 1]);

        $response = $this->invokeProtected('respondWithItem', [$resource, HttpStatus::CREATED]);

        static::assertSame(201, $response->getStatusCode());
    }

    /**
     * Test that respondWithCollection returns a collection response.
     *
     * @return void
     */
    public function testRespondWithCollectionReturnsCollectionResponse(): void
    {
        $collection = new ResourceCollection(collect([
            new JsonResource(['id' => 1, 'name' => 'First']),
            new JsonResource(['id' => 2, 'name' => 'Second']),
        ]));

        $response = $this->invokeProtected('respondWithCollection', [$collection]);

        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(200, $response->getStatusCode());
    }

    /**
     * Test that respondWithCollection accepts a custom status.
     *
     * @return void
     */
    public function testRespondWithCollectionAcceptsCustomStatus(): void
    {
        $collection = new ResourceCollection(collect([]));

        $response = $this->invokeProtected('respondWithCollection', [$collection, HttpStatus::ACCEPTED]);

        static::assertSame(202, $response->getStatusCode());
    }

    /**
     * Test that respondWithEventStream returns a StreamedResponse.
     *
     * @return void
     */
    public function testRespondWithEventStreamReturnsStreamedResponse(): void
    {
        $response = $this->invokeProtected('respondWithEventStream', [function (): void {}]);

        static::assertInstanceOf(StreamedResponse::class, $response);
        static::assertSame(200, $response->getStatusCode());
    }

    /**
     * Test that respondWithEventStream sets SSE headers.
     *
     * @return void
     */
    public function testRespondWithEventStreamSetsSseHeaders(): void
    {
        $response = $this->invokeProtected('respondWithEventStream', [function (): void {}]);

        static::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        $cache_control = $response->headers->get('Cache-Control');
        static::assertStringContainsString('no-cache', $cache_control);
        static::assertStringContainsString('no-transform', $cache_control);
        static::assertSame('keep-alive', $response->headers->get('Connection'));
        static::assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    /**
     * Test that respondWithEventStream accepts custom headers.
     *
     * @return void
     */
    public function testRespondWithEventStreamAcceptsCustomHeaders(): void
    {
        $headers = ['X-Stream-Id' => 'abc123'];

        $response = $this->invokeProtected('respondWithEventStream', [function (): void {}, 1, HttpStatus::OK, $headers]);

        static::assertSame('abc123', $response->headers->get('X-Stream-Id'));
        static::assertSame('text/event-stream', $response->headers->get('Content-Type'));
    }

    /**
     * Invoke a protected method on the controller.
     *
     * @param  string  $method
     * @param  array  $arguments
     * @return mixed
     */
    private function invokeProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($this->controller, $method);

        return $reflection->invoke($this->controller, ...$arguments);
    }
}
