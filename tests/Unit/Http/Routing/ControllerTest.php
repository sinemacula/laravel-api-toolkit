<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Routing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Routing\Controller;
use SineMacula\Http\Enums\HttpStatus;
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
final class ControllerTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /** @var \Tests\Fixtures\Controllers\TestingController */
    private TestingController $controller;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
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

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);

        self::assertArrayHasKey('data', $content);
        self::assertSame($data, $content['data']);
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

        self::assertSame(201, $response->getStatusCode());
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

        self::assertSame('custom-value', $response->headers->get('X-Custom-Header'));
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

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
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

        self::assertSame(201, $response->getStatusCode());
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

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
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

        self::assertSame(202, $response->getStatusCode());
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

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
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

        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        $cacheControl = $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-cache', $cacheControl);
        self::assertStringContainsString('no-transform', $cacheControl);
        self::assertSame('keep-alive', $response->headers->get('Connection'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
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

        self::assertSame('abc123', $response->headers->get('X-Stream-Id'));
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
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

        $abortCount = 0;

        // 4 calls: 0, 0, 0 → full iteration + sleep; 0 → second iteration
        // enters; heartbeat fires; call 4 at the second abort-check → break
        // (line 110).
        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 4 ? 1 : 0;
        });
        FunctionOverrides::set('flush', fn () => null);
        FunctionOverrides::set('ob_flush', fn () => null);
        FunctionOverrides::set('sleep', fn (int $_s) => 0);

        $callbackRan = false;

        /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
        $response = $this->invokeMethod(
            $this->controller,
            'respondWithEventStream',
            function () use (&$callbackRan): void {
                $callbackRan = true;
                $this->travel(25)->seconds();
            },
        );

        ob_start();
        $response->sendContent();
        ob_end_clean();

        self::assertTrue($callbackRan);
    }

    /**
     * Test that the HEARTBEAT_INTERVAL constant is defined and equals twenty.
     *
     * @SuppressWarnings("php:S3011")
     *
     * @return void
     */
    public function testHeartbeatIntervalConstantEqualsTwenty(): void
    {
        $reflection = new \ReflectionClass(Controller::class);

        $constant = $reflection->getReflectionConstant('HEARTBEAT_INTERVAL');

        self::assertNotFalse($constant);
        self::assertSame(20, $constant->getValue());
    }

    /**
     * Test that a subclass can override the HEARTBEAT_INTERVAL constant.
     *
     * @SuppressWarnings("php:S3011")
     *
     * @return void
     */
    public function testHeartbeatIntervalConstantCanBeOverriddenBySubclass(): void
    {
        $sub = new class extends TestingController {
            /** @var int The overridden heartbeat interval in seconds. */
            protected const int HEARTBEAT_INTERVAL = 5;
        };

        $reflection = new \ReflectionClass($sub);

        $constant = $reflection->getReflectionConstant('HEARTBEAT_INTERVAL');

        self::assertNotFalse($constant);
        self::assertSame(5, $constant->getValue());
    }

    /**
     * Test that respondWithEventStream emits an SSE error event and breaks the
     * stream loop when the callback throws a Throwable.
     *
     * @SuppressWarnings("php:S112")
     *
     * @return void
     */
    public function testRespondWithEventStreamEmitsErrorEventAndBreaksWhenCallbackThrows(): void
    {
        FunctionOverrides::set('connection_aborted', fn (): int => 0);
        FunctionOverrides::set('flush', fn () => null);
        FunctionOverrides::set('ob_flush', fn () => null);
        FunctionOverrides::set('sleep', fn (int $_s) => 0);

        $callCount = 0;

        /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
        $response = $this->invokeMethod(
            $this->controller,
            'respondWithEventStream',
            function () use (&$callCount): void {
                $callCount++;
                throw new \RuntimeException('Simulated stream failure');
            },
        );

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        self::assertStringContainsString("event: error\ndata: An error occurred\n\n", (string) $output);
        self::assertSame(1, $callCount);
    }

    /**
     * Test that respondWithData is callable from a subclass, asserting the
     * protected extension surface remains available to consuming
     * controllers.
     *
     * @return void
     */
    public function testRespondWithDataIsCallableFromSubclass(): void
    {
        $data = ['name' => 'Test'];

        $response = $this->createSubclassedController()->callData($data); // @phpstan-ignore method.notFound

        self::assertSame(200, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);

        self::assertSame($data, $content['data']);
    }

    /**
     * Test that respondWithItem is callable from a subclass.
     *
     * @return void
     */
    public function testRespondWithItemIsCallableFromSubclass(): void
    {
        $resource = new JsonResource(['id' => 1]);

        $response = $this->createSubclassedController()->callItem($resource); // @phpstan-ignore method.notFound

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Test that respondWithCollection is callable from a subclass.
     *
     * @return void
     */
    public function testRespondWithCollectionIsCallableFromSubclass(): void
    {
        $collection = new ResourceCollection(collect([
            new JsonResource(['id' => 1]),
        ]));

        $response = $this->createSubclassedController()->callCollection($collection); // @phpstan-ignore method.notFound

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Test that respondWithEventStream is callable from a subclass.
     *
     * @return void
     */
    public function testRespondWithEventStreamIsCallableFromSubclass(): void
    {
        $callback = static function (): void {
            // Stream callback placeholder
        };

        $response = $this->createSubclassedController()->callEventStream($callback); // @phpstan-ignore method.notFound

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
    }

    /**
     * Test that respondWithEventStream polls with a one-second interval by
     * default. The abort sequence allows a single full iteration, so sleep
     * must be invoked exactly once with the default interval.
     *
     * @return void
     */
    public function testRespondWithEventStreamDefaultsToOneSecondPollingInterval(): void
    {
        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
        });
        FunctionOverrides::set('flush', fn () => null);
        FunctionOverrides::set('ob_flush', fn () => null);

        $sleepArgs = [];

        FunctionOverrides::set('sleep', function (int $seconds) use (&$sleepArgs): int {
            $sleepArgs[] = $seconds;

            return 0;
        });

        $callback = static function (): void {
            // Stream callback placeholder
        };

        $response = $this->createSubclassedController()->callEventStream($callback); // @phpstan-ignore method.notFound

        ob_start();
        $response->sendContent();
        ob_end_clean();

        self::assertSame([1], $sleepArgs);
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

        $abortCount = 0;

        FunctionOverrides::set('connection_aborted', function () use (&$abortCount): int {
            return ++$abortCount >= 3 ? 1 : 0;
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

        self::assertInstanceOf(StreamedResponse::class, $response);
    }

    /**
     * Create a controller subclass exposing the protected response helpers.
     *
     * Calling the helpers from a subclass scope asserts they remain part of
     * the protected extension surface available to consuming controllers.
     *
     * @return object
     */
    private function createSubclassedController(): object
    {
        return new class extends TestingController {
            /**
             * @param  array<string, mixed>  $data
             * @return \Illuminate\Http\JsonResponse
             */
            public function callData(array $data): JsonResponse
            {
                return $this->respondWithData($data);
            }

            /**
             * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
             * @return \Illuminate\Http\JsonResponse
             */
            public function callItem(JsonResource $resource): JsonResponse
            {
                return $this->respondWithItem($resource);
            }

            /**
             * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
             * @return \Illuminate\Http\JsonResponse
             */
            public function callCollection(ResourceCollection $collection): JsonResponse
            {
                return $this->respondWithCollection($collection);
            }

            /**
             * @param  callable(): void  $callback
             * @return \Symfony\Component\HttpFoundation\StreamedResponse
             */
            public function callEventStream(callable $callback): StreamedResponse
            {
                return $this->respondWithEventStream($callback);
            }
        };
    }
}
