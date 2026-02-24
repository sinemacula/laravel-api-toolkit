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
 * @copyright   2026 Sine Macula Limited.
 */
abstract class Controller extends LaravelController
{
    use ValidatesRequests;

    /** The SSE heartbeat interval in seconds. */
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
     * @param  callable(): void  $callback
     * @param  int  $interval
     * @param  \SineMacula\ApiToolkit\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
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
            $this->runEventStream($callback, $interval);
        }, $status->getCode(), $headers);
    }

    /**
     * Execute the SSE stream loop.
     *
     * Emits an initial keep-alive comment, then polls the callback on each
     * iteration, sending a heartbeat comment when the interval expires.
     * Exits when the client disconnects or the callback throws.
     *
     * @param  callable(): void  $callback
     * @param  int  $interval
     * @return void
     */
    private function runEventStream(callable $callback, int $interval): void
    {
        echo ":\n\n";
        flush();

        $heartbeat_timestamp = now();

        while (true) {

            if (connection_aborted()) {
                break;
            }

            if (!$this->runStreamCallback($callback)) {
                break;
            }

            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();

            if ($heartbeat_timestamp->diffInSeconds(now()) >= static::HEARTBEAT_INTERVAL) {
                echo ":\n\n";
                flush();
                $heartbeat_timestamp = now();
            }

            // @phpstan-ignore-next-line if.alwaysFalse (connection state may change between the two checks per iteration)
            if (connection_aborted()) {
                break;
            }

            sleep($interval);
        }
    }

    /**
     * Invoke the stream callback, emitting an SSE error event on failure.
     *
     * Returns true when the callback succeeded, false when it threw so the
     * caller can break the stream loop.
     *
     * @param  callable(): void  $callback
     * @return bool
     */
    private function runStreamCallback(callable $callback): bool
    {
        try {
            $callback();
            return true;
        } catch (\Throwable $e) {
            report($e);
            echo "event: error\n\n";
            flush();
            return false;
        }
    }
}
