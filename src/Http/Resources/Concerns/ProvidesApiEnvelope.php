<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provides the toolkit's response envelope for resource collections.
 *
 * Single source of truth for the pagination contract shared by every toolkit
 * collection: the `meta`/`links` blocks for length-aware and cursor paginators
 * and the `Total-Count` response header. Hosting it in a trait lets both the
 * Eloquent-backed collection and non-Eloquent (report) collections emit an
 * identical envelope without inheriting each other's item-mapping concerns.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait ProvidesApiEnvelope
{
    /**
     * Customize the response for a request.
     *
     * The request parameter is unused but required by Laravel's resource
     * response signature.
     *
     * @SuppressWarnings("php:S1172")
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

        $response->headers->set('Total-Count', (string) $this->resource->total());
    }

    /**
     * Customize the pagination information for the resource.
     *
     * The request, paginated, and default parameters are unused but required by
     * Laravel's resource-collection signature.
     *
     * @SuppressWarnings("php:S1172")
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
