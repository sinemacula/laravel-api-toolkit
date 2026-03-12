<?php

namespace SineMacula\ApiToolkit\Http\Routing;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller as LaravelController;
use Illuminate\Support\Facades\Response;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use SineMacula\ApiToolkit\Sse\EventStream;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Base API controller.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class Controller extends LaravelController
{
    use ValidatesRequests;

    /** @var int The SSE heartbeat interval in seconds. */
    protected const int HEARTBEAT_INTERVAL = 20;

    /**
     * Respond with raw array data as a JSON response.
     *
     * @param  array<string, mixed>  $data
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithData(array $data, HttpStatus $status = HttpStatus::OK, array $headers = []): JsonResponse
    {
        return Response::json(['data' => $data], $status->getCode(), $headers);
    }

    /**
     * Respond with a JSON resource representing a single item.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithItem(JsonResource $resource, HttpStatus $status = HttpStatus::OK, array $headers = []): JsonResponse
    {
        return $resource->response()->setStatusCode($status->getCode())->withHeaders($headers);
    }

    /**
     * Respond with a JSON resource collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithCollection(ResourceCollection $collection, HttpStatus $status = HttpStatus::OK, array $headers = []): JsonResponse
    {
        return $collection->response()->setStatusCode($status->getCode())->withHeaders($headers);
    }

    /**
     * Respond with an SSE event stream.
     *
     * Delegates to EventStream for response construction, the polling loop,
     * heartbeat emission, and error handling.
     *
     * @param  callable(): void|callable(\SineMacula\ApiToolkit\Sse\Emitter): void  $callback
     * @param  int  $interval
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function respondWithEventStream(callable $callback, int $interval = 1, HttpStatus $status = HttpStatus::OK, array $headers = []): StreamedResponse
    {
        return (new EventStream(static::HEARTBEAT_INTERVAL))
            ->toResponse($callback, $interval, $status, $headers);
    }
}
