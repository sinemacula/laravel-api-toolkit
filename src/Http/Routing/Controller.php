<?php

namespace SineMacula\ApiToolkit\Http\Routing;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller as LaravelController;
use Illuminate\Support\Facades\Response;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Base API controller.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
abstract class Controller extends LaravelController
{
    use ValidatesRequests;

    /**
     * Respond with raw array data as a JSON response.
     *
     * @param  array  $data
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $status
     * @param  array  $headers
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
     * @param  array  $headers
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
     * @param  array  $headers
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithCollection(ResourceCollection $collection, HttpStatus $status = HttpStatus::OK, array $headers = []): JsonResponse
    {
        return $collection->response()->setStatusCode($status->getCode())->withHeaders($headers);
    }

    /**
     * Respond with an SSE event stream.
     *
     * @param  callable  $callback
     * @param  int  $interval
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $status
     * @param  array  $headers
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function respondWithEventStream(callable $callback, int $interval = 1, HttpStatus $status = HttpStatus::OK, array $headers = []): StreamedResponse
    {
        $headers = array_merge($headers, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);

        return Response::stream(function () use ($callback, $interval): void {

            echo ":\n\n";
            flush();

            $heartbeat_interval  = 20;
            $heartbeat_timestamp = now();

            while (true) {

                if (connection_aborted()) {
                    break;
                }

                $callback();

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();

                if ($heartbeat_timestamp->diffInSeconds(now()) >= $heartbeat_interval) {
                    echo ":\n\n";
                    flush();
                    $heartbeat_timestamp = now();
                }

                if (connection_aborted()) {
                    break;
                }

                sleep($interval);
            }

        }, $status->getCode(), $headers);
    }
}
