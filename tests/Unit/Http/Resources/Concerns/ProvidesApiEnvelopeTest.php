<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ProvidesApiEnvelope;
use SineMacula\ApiToolkit\Http\Resources\ToolkitCollection;
use SineMacula\ApiToolkit\Repositories\Pagination\CappedLengthAwarePaginator;
use Tests\TestCase;

/**
 * Tests for the ProvidesApiEnvelope trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(ProvidesApiEnvelope::class)]
final class ProvidesApiEnvelopeTest extends TestCase
{
    /** @var string The path the paginators build their links from */
    private const string PATH = 'https://api.example.com/users';

    /**
     * Test that a middle length-aware page carries its exact meta and links.
     *
     * @return void
     */
    public function testMiddleLengthAwarePageCarriesItsMetaAndLinks(): void
    {
        $paginator = new LengthAwarePaginator(['b', 'c'], 5, 2, 2, ['path' => self::PATH]);

        self::assertSame([
            'meta'  => ['total' => 5, 'total_capped' => false, 'count' => 2, 'continue' => true],
            'links' => [
                'self'  => self::PATH . '?page=2',
                'first' => self::PATH . '?page=1',
                'prev'  => self::PATH . '?page=1',
                'next'  => self::PATH . '?page=3',
                'last'  => self::PATH . '?page=3',
            ],
        ], $this->information($paginator));
    }

    /**
     * Test that the last length-aware page has no next link.
     *
     * @return void
     */
    public function testLastLengthAwarePageHasNoNextLink(): void
    {
        $paginator = new LengthAwarePaginator(['e'], 5, 2, 3, ['path' => self::PATH]);

        self::assertSame([
            'meta'  => ['total' => 5, 'total_capped' => false, 'count' => 1, 'continue' => false],
            'links' => [
                'self'  => self::PATH . '?page=3',
                'first' => self::PATH . '?page=1',
                'prev'  => self::PATH . '?page=2',
                'next'  => null,
                'last'  => self::PATH . '?page=3',
            ],
        ], $this->information($paginator));
    }

    /**
     * Test that a capped page flags its total.
     *
     * @return void
     */
    public function testCappedPageFlagsItsTotal(): void
    {
        $paginator = new CappedLengthAwarePaginator(['a', 'b'], 6, 2, 1, ['path' => self::PATH]);

        self::assertSame(['total' => 6, 'total_capped' => true, 'count' => 2, 'continue' => true], $this->information($paginator)['meta']);
    }

    /**
     * Test that a cursor page carries the continue flag and its cursor links.
     *
     * @return void
     */
    public function testCursorPageCarriesItsContinueFlagAndLinks(): void
    {
        $paginator = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3]], 2, null, ['path' => self::PATH, 'parameters' => ['id']]);
        $next      = (new Cursor(['id' => 2]))->encode();

        self::assertSame([
            'meta'  => ['continue' => true],
            'links' => [
                'self' => request()->fullUrl(),
                'prev' => null,
                'next' => self::PATH . '?cursor=' . $next,
            ],
        ], $this->information($paginator));
    }

    /**
     * Test that a paginator that does not count takes the cursor shape.
     *
     * @return void
     */
    public function testSimplePageTakesTheCursorShape(): void
    {
        $paginator = new Paginator(['c', 'd', 'e'], 2, 2, ['path' => self::PATH]);

        self::assertSame([
            'meta'  => ['continue' => true],
            'links' => [
                'self' => self::PATH . '?page=2',
                'prev' => self::PATH . '?page=1',
                'next' => self::PATH . '?page=3',
            ],
        ], $this->information($paginator));
    }

    /**
     * Test that a plain collection adds no pagination information.
     *
     * @return void
     */
    public function testPlainCollectionAddsNoPaginationInformation(): void
    {
        self::assertSame([], $this->information(collect(['a'])));
    }

    /**
     * Test that only an exact length-aware total is sent as Total-Count.
     *
     * @return void
     */
    public function testOnlyAnExactLengthAwareTotalIsSent(): void
    {
        self::assertSame('5', $this->totalCount(new LengthAwarePaginator(['a'], 5, 2, 1)));
        self::assertNull($this->totalCount(new CappedLengthAwarePaginator(['a', 'b'], 6, 2, 1)));
        self::assertNull($this->totalCount(new Paginator(['a'], 2, 1)));
        self::assertNull($this->totalCount(new CursorPaginator(['a'], 2)));
        self::assertNull($this->totalCount(collect(['a'])));
    }

    /**
     * Build the pagination information for the given resource.
     *
     * @param  mixed  $resource
     * @return array<string, array<string, mixed>>
     */
    private function information(mixed $resource): array
    {
        return $this->collection($resource)->paginationInformation(Request::create('/'), [], []);
    }

    /**
     * Read the Total-Count header sent for the given resource.
     *
     * @param  mixed  $resource
     * @return string|null
     */
    private function totalCount(mixed $resource): ?string
    {
        $response = new JsonResponse([]);

        $this->collection($resource)->withResponse(Request::create('/'), $response);

        return $response->headers->get('Total-Count');
    }

    /**
     * Wrap the resource in a collection using the envelope.
     *
     * @param  mixed  $resource
     * @return \SineMacula\ApiToolkit\Http\Resources\ToolkitCollection
     */
    private function collection(mixed $resource): ToolkitCollection
    {
        return new class ($resource, JsonResource::class) extends ToolkitCollection {};
    }
}
