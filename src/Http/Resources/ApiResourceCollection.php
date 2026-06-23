<?php

namespace SineMacula\ApiToolkit\Http\Resources;

use Illuminate\Contracts\Pagination\CursorPaginator;
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
 * @copyright   2026 Sine Macula Limited.
 */
final class ApiResourceCollection extends AnonymousResourceCollection
{
    /** @var array<int, string>|null Explicit list of fields to be returned in the collection */
    protected ?array $fields;

    /** @var array<int, string>|null Explicit list of fields to be excluded in the response */
    protected ?array $excludedFields;

    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<int|string, array<string, mixed>>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource> $resource_class */
        $resource_class = $this->collects;

        return collect($this->collection)->map(function ($item) use ($resource_class, $request) {

            if ($item instanceof ApiResource) {

                if (isset($this->fields)) {
                    $item->withFields($this->fields);
                }

                if (isset($this->excludedFields)) {
                    $item->withoutFields($this->excludedFields);
                }

                return $item->resolve($request);
            }

            return (new $resource_class($item, false, $this->fields ?? null, $this->excludedFields ?? null))->resolve($request);
        })->all();
    }

    /**
     * Overrides the default fields and any requested fields with a provided
     * set.
     *
     * @param  array<int, string>|null  $fields
     * @return static
     */
    public function withFields(?array $fields = null): static
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Removes certain fields from the response.
     *
     * @param  array<int, string>|null  $fields
     * @return static
     */
    public function withoutFields(?array $fields = null): static
    {
        $this->excludedFields = $fields;

        return $this;
    }

    /**
     * Customize the response for a request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\JsonResponse  $response
     * @return void
     */
    #[\Override]
    public function withResponse(Request $request, JsonResponse $response): void
    {
        if (!($this->resource instanceof LengthAwarePaginator)) {
            return;
        }

        $response->headers->set('Total-Count', $this->resource->total());
    }

    /**
     * Customize the pagination information for the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, array<string, mixed>>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        if ($this->resource instanceof LengthAwarePaginator) {
            return [
                'meta'  => $this->buildPaginationMeta($this->resource),
                'links' => $this->buildPaginationLinks($this->resource),
            ];
        }

        if ($this->resource instanceof CursorPaginator) {
            return [
                'meta'  => $this->buildCursorPaginationMeta($this->resource),
                'links' => $this->buildCursorPaginationLinks($this->resource),
            ];
        }

        return [];
    }

    /**
     * Build the meta for the paginated response.
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<int|string, mixed>  $paginator
     * @return array<string, bool|int>
     */
    private function buildPaginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'total'    => $paginator->total(),
            'count'    => count($paginator->items()),
            'continue' => $paginator->hasMorePages(),
        ];
    }

    /**
     * Build the links for the paginated response.
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<int|string, mixed>  $paginator
     * @return array<string, string|null>
     */
    private function buildPaginationLinks(LengthAwarePaginator $paginator): array
    {
        return [
            'self'  => $paginator->url($paginator->currentPage()),
            'first' => $paginator->url(1),
            'prev'  => $paginator->previousPageUrl(),
            'next'  => $paginator->nextPageUrl(),
            'last'  => $paginator->url($paginator->lastPage()),
        ];
    }

    /**
     * Build the meta for cursor-based pagination.
     *
     * @param  \Illuminate\Contracts\Pagination\CursorPaginator<int|string, mixed>  $paginator
     * @return array<string, bool>
     */
    private function buildCursorPaginationMeta(CursorPaginator $paginator): array
    {
        return [
            'continue' => $paginator->hasMorePages(),
        ];
    }

    /**
     * Build the links for cursor-based pagination.
     *
     * @param  \Illuminate\Contracts\Pagination\CursorPaginator<int|string, mixed>  $paginator
     * @return array<string, string|null>
     */
    private function buildCursorPaginationLinks(CursorPaginator $paginator): array
    {
        return [
            'self' => request()->fullUrl(),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];
    }
}
