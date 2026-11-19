<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Repositories\Pagination\CappedLengthAwarePaginator;

/**
 * Provides the toolkit's pagination envelope for resource collections.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait ProvidesApiEnvelope
{
    /**
     * Customize the response for a request.
     *
     * A capped total is not sent, since a header carries no flag to mark it.
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
        if (!($this->resource instanceof LengthAwarePaginator) || $this->resource instanceof CappedLengthAwarePaginator) {
            return;
        }

        $response->headers->set('Total-Count', (string) $this->resource->total());
    }

    /**
     * Customize the pagination information for the resource.
     *
     * A paginator that does not count takes the cursor shape.
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
        return match (true) {
            $this->resource instanceof LengthAwarePaginator => [
                'meta'  => $this->buildPaginationMeta($this->resource),
                'links' => $this->buildPaginationLinks($this->resource),
            ],
            $this->resource instanceof CursorPaginator => [
                'meta'  => $this->buildContinuationMeta($this->resource),
                'links' => $this->buildCursorPaginationLinks($this->resource),
            ],
            $this->resource instanceof Paginator => [
                'meta'  => $this->buildContinuationMeta($this->resource),
                'links' => $this->buildSimplePaginationLinks($this->resource),
            ],
            default => [],
        };
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
            'total'        => $paginator->total(),
            'total_capped' => $paginator instanceof CappedLengthAwarePaginator,
            'count'        => count($paginator->items()),
            'continue'     => $paginator->hasMorePages(),
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
     * Build the meta for pagination that does not count.
     *
     * @param  \Illuminate\Contracts\Pagination\CursorPaginator<int|string, mixed>|\Illuminate\Contracts\Pagination\Paginator<int|string, mixed>  $paginator
     * @return array<string, bool>
     */
    private function buildContinuationMeta(CursorPaginator|Paginator $paginator): array
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

    /**
     * Build the links for simple pagination.
     *
     * @param  \Illuminate\Contracts\Pagination\Paginator<int|string, mixed>  $paginator
     * @return array<string, string|null>
     */
    private function buildSimplePaginationLinks(Paginator $paginator): array
    {
        return [
            'self' => $paginator->url($paginator->currentPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];
    }
}
