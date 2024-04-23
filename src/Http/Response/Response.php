<?php

namespace SineMacula\ApiToolkit\Http\Response;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * @deprecated
 *
 * @author      Ben Carey <ben.carey@verifast.com>
 * @copyright   2024 Verifast, Inc.
 */
readonly class Response
{
    /**
     * Constructor.
     *
     * @param  \Illuminate\Contracts\Routing\ResponseFactory  $factory
     * @param  bool                                           $pretty
     */
    public function __construct(

        /** The response factory instance */
        private ResponseFactory $factory,

        /** Boolean to indicate whether to return pretty JSON */
        private bool $pretty = false

    ) {}

    /**
     * Return a data response.
     *
     * @param  array  $data
     * @param  int    $status_code
     * @param  array  $headers
     * @return \Illuminate\Http\JsonResponse
     */
    public function data(array $data = [], int $status_code = 200, array $headers = []): JsonResponse
    {
        return $this->respond([
            'data' => $data
        ], $status_code, $headers);
    }

    /**
     * Responds with json from a given array.
     *
     * @param  array  $data
     * @param  int    $status_code
     * @param  array  $headers
     * @return \Illuminate\Http\JsonResponse
     */
    private function respond(array $data, int $status_code = 200, array $headers = []): JsonResponse
    {
        $options = $this->pretty ? JSON_PRETTY_PRINT : 0;

        return $this->factory->json($data, $status_code, $headers, $options);
    }

    /**
     * Return a single resource item response.
     *
     * @param  \Illuminate\Http\Resources\Json\JsonResource  $item
     * @param  int                                           $status_code
     * @param  array                                         $headers
     * @return \Illuminate\Http\JsonResponse
     */
    public function item(JsonResource $item, int $status_code = 200, array $headers = []): JsonResponse
    {
        return $this->respond(['data' => $item->resolve()], $status_code, $headers);
    }

    /**
     * Return a paginated resource response.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $items
     * @param  int                                                 $status_code
     * @param  array                                               $headers
     * @return \Illuminate\Http\JsonResponse
     */
    public function paginate(ResourceCollection $items, int $status_code = 200, array $headers = []): JsonResponse
    {
        // Need to figure out how to do this... Probably need to pass the resource class...

        $response = [
            'data'  => $paginator->isEmpty() ? [] : $transformer->collection($paginator->items()),
            'meta'  => $this->buildPaginationMeta($paginator, $meta),
            'links' => $this->buildPaginationLinks($paginator)
        ];

        $headers['Total-Count'] = $paginator->total();

        return $this->respond($response, $status_code, $headers);
    }

    /**
     * Return collection of items response.
     *
     * @param  \Illuminate\Http\Resources\Json\ResourceCollection  $items
     * @param  int                                                 $status_code
     * @param  array                                               $headers
     * @return \Illuminate\Http\JsonResponse
     */
    public function collection(ResourceCollection $items, int $status_code = 200, array $headers = []): JsonResponse
    {
        return $this->respond(['data' => $items->resolve()], $status_code, $headers);
    }

    /**
     * Build the meta for the paginated response.
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator  $paginator
     * @param  array                                                  $meta
     * @return array
     */
    private function buildPaginationMeta(LengthAwarePaginator $paginator, array $meta): array
    {
        return array_merge($meta, [
            'total'    => $paginator->total(),
            'count'    => count($paginator->items()),
            'continue' => $paginator->hasMorePages()
        ]);
    }

    /**
     * Build the links for the paginated response.
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator  $paginator
     * @return array
     */
    private function buildPaginationLinks(LengthAwarePaginator $paginator): array
    {
        return [
            'self'  => $paginator->url($paginator->currentPage()),
            'first' => $paginator->url(1),
            'prev'  => $paginator->previousPageUrl(),
            'next'  => $paginator->nextPageUrl(),
            'last'  => $paginator->url($paginator->lastPage())
        ];
    }
}
