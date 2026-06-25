<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Routing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Routing\Controller;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Concerns\InteractsWithNonPublicMembers;
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
        };
    }
}
