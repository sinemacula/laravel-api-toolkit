<?php

namespace Tests\Unit\Http\Routing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use SineMacula\ApiToolkit\Http\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Controllers\TestingController;
use Tests\Fixtures\Support\FunctionOverrides;
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
    use InteractsWithNonPublicMembers;

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

        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $this->invokeMethod($this->controller, 'respondWithData', $data);

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

        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $this->invokeMethod($this->controller, 'respondWithData', $data, HttpStatus::CREATED);

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

        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $this->invokeMethod($this->controller, 'respondWithData', $data, HttpStatus::OK, $headers);

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

        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $this->invokeMethod($this->controller, 'respondWithItem', $resource);

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

        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $this->invokeMethod($this->controller, 'respondWithItem', $resource, HttpStatus::CREATED);

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

        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $this->invokeMethod($this->controller, 'respondWithCollection', $collection);

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

        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $this->invokeMethod($this->controller, 'respondWithCollection', $collection, HttpStatus::ACCEPTED);

        static::assertSame(202, $response->getStatusCode());
    }

    /**
     * Test that respondWithEventStream returns a StreamedResponse.
     *
     * @return void
     */
    public function testRespondWithEventStreamReturnsStreamedResponse(): void
    {
        /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
        $response = $this->invokeMethod($this->controller, 'respondWithEventStream', static function (): void {
            // Stream callback placeholder
        });

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
        /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
        $response = $this->invokeMethod($this->controller, 'respondWithEventStream', static function (): void {
            // Stream callback placeholder
        });

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

        /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
        $response = $this->invokeMethod($this->controller, 'respondWithEventStream', static function (): void {
            // Stream callback placeholder
        }, 1, HttpStatus::OK, $headers);

        static::assertSame('abc123', $response->headers->get('X-Stream-Id'));
        static::assertSame('text/event-stream', $response->headers->get('Content-Type'));
    }

    /**
     * Test that the stream callback body executes, including the heartbeat
     * and sleep paths.
     *
     * Uses FunctionOverrides to control connection_aborted(), flush(), and
     * sleep(). The user-supplied callback advances Carbon's test clock past
     * the 20-second heartbeat threshold so the inner heartbeat block fires
     * during the first full iteration.
     *
     * @return void
     */
    public function testRespondWithEventStreamExecutesStreamBody(): void
    {
        $this->travelTo(now());

        $abort_count = 0;

        // 4 calls: 0, 0, 0 → full iteration + sleep; 0 → second iteration enters;
        // heartbeat fires; call 4 at the second abort-check → break (line 110).
        FunctionOverrides::set('connection_aborted', function () use (&$abort_count): int {
            return ++$abort_count >= 4 ? 1 : 0;
        });
        FunctionOverrides::set('flush', fn () => null);
        FunctionOverrides::set('ob_flush', fn () => null);
        FunctionOverrides::set('sleep', fn (int $_s) => 0);

        $callback_ran = false;

        /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
        $response = $this->invokeMethod(
            $this->controller,
            'respondWithEventStream',
            function () use (&$callback_ran): void {
                $callback_ran = true;
                $this->travel(25)->seconds();
            },
        );

        ob_start();
        $response->sendContent();
        ob_end_clean();

        static::assertTrue($callback_ran);
    }

    /**
     * Test that the stream loop breaks on the first abort check at the start
     * of the second iteration.
     *
     * 3 abort calls: 0, 0, 1. The first iteration runs fully (callback +
     * heartbeat + sleep), then the third call at the first per-iteration
     * check of iteration 2 returns 1, exercising the break on line 92.
     *
     * @return void
     */
    public function testRespondWithEventStreamBreaksOnFirstCheckOfSecondIteration(): void
    {
        $this->travelTo(now());

        $abort_count = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abort_count): int {
            return ++$abort_count >= 3 ? 1 : 0;
        });
        FunctionOverrides::set('flush', fn () => null);
        FunctionOverrides::set('ob_flush', fn () => null);
        FunctionOverrides::set('sleep', fn (int $_s) => 0);

        /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
        $response = $this->invokeMethod(
            $this->controller,
            'respondWithEventStream',
            function (): void {
                $this->travel(25)->seconds();
            },
        );

        ob_start();
        $response->sendContent();
        ob_end_clean();

        static::assertInstanceOf(StreamedResponse::class, $response);
    }
}
