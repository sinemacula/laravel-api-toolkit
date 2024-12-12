<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The base API resource collection.
 *
 * This handles dynamic field filtering based on API query parameters. It
 * leverages a global query parser to determine which fields should be included
 * in the response.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class ApiResourceCollection extends AnonymousResourceCollection
{
    /** @var array|null Explicit list of fields to be returned in the collection */
    protected ?array $fields;

    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        return $this->collection->map(fn (ApiResource $item) => $item->withFields($this->fields ?? null)->resolve($request))->all();
    }

    /**
     * Overrides the default fields and any requested fields with a provided
     * set.
     *
     * @param  array|null  $fields
     * @return static
     */
    public function withFields(?array $fields = null): static
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Customize the response for a request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\JsonResponse  $response
     * @return void
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        if ($this->resource instanceof LengthAwarePaginator) {
            $response->headers->set('Total-Count', $this->resource->total());
        }
    }

    /**
     * Customize the pagination information for the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $paginated
     * @param  array  $default
     * @return array
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        if (!$this->resource instanceof LengthAwarePaginator) {
            return [];
        }

        return [
            'meta'  => $this->buildPaginationMeta($this->resource),
            'links' => $this->buildPaginationLinks($this->resource)
        ];
    }

    /**
     * Build the meta for the paginated response.
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator  $paginator
     * @return array
     */
    private function buildPaginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'total'    => $paginator->total(),
            'count'    => count($paginator->items()),
            'continue' => $paginator->hasMorePages()
        ];
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
