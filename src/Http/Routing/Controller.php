<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Routing;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Response;
use SineMacula\Http\Enums\HttpStatus;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Base API controller.
 *
 * Skips the framework controller: its middleware() clashes with HasMiddleware.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class Controller
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
    protected function respondWithData(array $data, HttpStatus $status = HttpStatus::OK, array $headers = []): JsonResponse
    {
        return Response::json(['data' => $data], $status->getCode(), $headers);
    }

    /**
     * Respond with a resource representing a single item, or the export the
     * request negotiates.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $resource
     * @param  \SineMacula\Http\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function respondWithItem(JsonResource $resource, HttpStatus $status = HttpStatus::OK, array $headers = []): HttpResponse
    {
        return $this->respondWith($resource->response(), $status, $headers);
    }

    /**
     * Respond with a resource collection, or the export the request negotiates.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $collection
     * @param  \SineMacula\Http\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function respondWithCollection(ResourceCollection $collection, HttpStatus $status = HttpStatus::OK, array $headers = []): HttpResponse
    {
        return $this->respondWith($collection->response(), $status, $headers);
    }

    /**
     * Apply the status and headers to a resource's response.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @param  \SineMacula\Http\Enums\HttpStatus  $status
     * @param  array<string, string>  $headers
     * @return \Symfony\Component\HttpFoundation\Response
     */
    private function respondWith(HttpResponse $response, HttpStatus $status, array $headers): HttpResponse
    {
        $response->setStatusCode($status->getCode());
        $response->headers->add($headers);

        return $response;
    }
}
