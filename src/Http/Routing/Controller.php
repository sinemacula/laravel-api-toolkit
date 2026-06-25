<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Routing;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller as LaravelController;
use Illuminate\Support\Facades\Response;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Base API controller.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class Controller extends LaravelController
{
    use ValidatesRequests;

    /**
     * Respond with raw array data as a JSON response.
     *
     * @param  array<string, mixed>  $data
     * @param  \SineMacula\Http\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithData(
        array $data,
        HttpStatus $status = HttpStatus::OK,
        array $headers = [],
    ): JsonResponse {
        return Response::json(['data' => $data], $status->getCode(), $headers);
    }

    /**
     * Respond with a JSON resource representing a single item.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  \SineMacula\Http\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithItem(
        JsonResource $resource,
        HttpStatus $status = HttpStatus::OK,
        array $headers = [],
    ): JsonResponse {
        return $resource->response()->setStatusCode($status->getCode())->withHeaders($headers);
    }

    /**
     * Respond with a JSON resource collection.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  \SineMacula\Http\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithCollection(
        ResourceCollection $collection,
        HttpStatus $status = HttpStatus::OK,
        array $headers = [],
    ): JsonResponse {
        return $collection->response()->setStatusCode($status->getCode())->withHeaders($headers);
    }
}
