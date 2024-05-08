<?php

namespace SineMacula\ApiToolkit\Http\Controllers;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller as LaravelController;
use Illuminate\Support\Facades\Response;

/**
 * Base API controller.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
abstract class Controller extends LaravelController
{
    use ValidatesRequests;

    /**
     * Respond with raw array data as a JSON response.
     *
     * @param  array  $data
     * @param  int  $status
     * @param  array  $headers
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithData(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return Response::json(['data' => $data], $status, $headers);
    }

    /**
     * Respond with a JSON resource representing a single item.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  int  $status
     * @param  array  $headers
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithItem(JsonResource $resource, int $status = 200, array $headers = []): JsonResponse
    {
        return $resource->response()->setStatusCode($status)->withHeaders($headers);
    }

    /**
     * Respond with a JSON resource collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  int  $status
     * @param  array  $headers
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithCollection(ResourceCollection $collection, int $status = 200, array $headers = []): JsonResponse
    {
        return $collection->response()->setStatusCode($status)->withHeaders($headers);
    }
}
