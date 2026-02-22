<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Controllers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use SineMacula\ApiToolkit\Enums\HttpStatus;
use SineMacula\ApiToolkit\Http\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TestingController extends Controller
{
    public function data(array $payload, HttpStatus $status = HttpStatus::OK, array $headers = []): \Illuminate\Http\JsonResponse
    {
        return $this->respondWithData($payload, $status, $headers);
    }

    public function item(JsonResource $resource, HttpStatus $status = HttpStatus::OK, array $headers = []): \Illuminate\Http\JsonResponse
    {
        return $this->respondWithItem($resource, $status, $headers);
    }

    public function collection(ResourceCollection $collection, HttpStatus $status = HttpStatus::OK, array $headers = []): \Illuminate\Http\JsonResponse
    {
        return $this->respondWithCollection($collection, $status, $headers);
    }

    public function stream(callable $callback, int $interval = 1, HttpStatus $status = HttpStatus::OK, array $headers = []): StreamedResponse
    {
        return $this->respondWithEventStream($callback, $interval, $status, $headers);
    }
}
