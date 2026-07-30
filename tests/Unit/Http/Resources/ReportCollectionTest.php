<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ProvidesApiEnvelope;
use SineMacula\ApiToolkit\Http\Resources\ReportCollection;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Reports\SalesSummaryResource;
use Tests\Fixtures\Reports\SalesSummaryRow;
use Tests\TestCase;

/**
 * Tests for the report resource collection and the shared response envelope.
 *
 * Mirrors the entity collection's pagination tests against data-transfer-object
 * items, proving a report emits an envelope identical to entity collections.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1075")
 *
 * @internal
 */
#[CoversClass(ReportCollection::class)]
#[CoversTrait(ProvidesApiEnvelope::class)]
final class ReportCollectionTest extends TestCase
{
    /** @var string Base path used to build paginator links in tests */
    private const string PAGINATION_PATH = 'http://localhost/reports/sales';

    /**
     * Test that toArray transforms each DTO item via the report resource.
     *
     * @return void
     */
    public function testToArrayTransformsDtoItemsViaReportResource(): void
    {
        $rows = collect([
            new SalesSummaryRow('2026-01', 5, '50.00'),
            new SalesSummaryRow('2026-02', 9, '90.00'),
        ]);

        $collection = new ReportCollection($rows, SalesSummaryResource::class);

        $result = $collection->toArray(Request::create('/', HttpMethod::GET->getVerb()));

        self::assertCount(2, $result);
        self::assertSame('sales-summary', $result[0]['_type']);
        self::assertSame('2026-01', $result[0]['month']);
        self::assertSame(9, $result[1]['orders']);
    }

    /**
     * Test that withResponse sets Total-Count header for LengthAwarePaginator.
     *
     * @return void
     */
    public function testWithResponseSetsTotalCountHeaderForLengthAwarePaginator(): void
    {
        $items     = [new SalesSummaryRow('2026-01', 1, '1.00')];
        $paginator = new LengthAwarePaginator($items, 50, 15, 1);

        $collection = new ReportCollection($paginator, SalesSummaryResource::class);

        $request  = Request::create('/', HttpMethod::GET->getVerb());
        $response = new JsonResponse([]);

        $collection->withResponse($request, $response);

        self::assertSame('50', $response->headers->get('Total-Count'));
    }

    /**
     * Test that withResponse does not set header for non-paginator resources.
     *
     * @return void
     */
    public function testWithResponseDoesNotSetHeaderForNonPaginator(): void
    {
        $collection = new ReportCollection(collect([]), SalesSummaryResource::class);

        $request  = Request::create('/', HttpMethod::GET->getVerb());
        $response = new JsonResponse([]);

        $collection->withResponse($request, $response);

        self::assertNull($response->headers->get('Total-Count'));
    }

    /**
     * Test that paginationInformation for LengthAwarePaginator returns the
     * toolkit's meta and links shape.
     *
     * @return void
     */
    public function testPaginationInformationForLengthAwarePaginator(): void
    {
        $items     = [new SalesSummaryRow('2026-01', 1, '1.00')];
        $paginator = new LengthAwarePaginator($items, 50, 15, 2, [
            'path' => self::PAGINATION_PATH,
        ]);

        $collection = new ReportCollection($paginator, SalesSummaryResource::class);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->paginationInformation($request, [], []);

        self::assertSame(50, $result['meta']['total']);
        self::assertSame(1, $result['meta']['count']);
        self::assertArrayHasKey('continue', $result['meta']);
        self::assertArrayHasKey('self', $result['links']);
        self::assertArrayHasKey('first', $result['links']);
        self::assertArrayHasKey('prev', $result['links']);
        self::assertArrayHasKey('next', $result['links']);
        self::assertArrayHasKey('last', $result['links']);
    }

    /**
     * Test that paginationInformation for CursorPaginator returns cursor meta
     * and links.
     *
     * @return void
     */
    public function testPaginationInformationForCursorPaginator(): void
    {
        $items     = [new SalesSummaryRow('2026-01', 1, '1.00')];
        $paginator = new CursorPaginator($items, 15, null, [
            'path' => self::PAGINATION_PATH,
        ]);

        $collection = new ReportCollection($paginator, SalesSummaryResource::class);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->paginationInformation($request, [], []);

        self::assertArrayHasKey('continue', $result['meta']);
        self::assertArrayHasKey('self', $result['links']);
        self::assertArrayHasKey('prev', $result['links']);
        self::assertArrayHasKey('next', $result['links']);
    }

    /**
     * Test that paginationInformation for a non-paginator returns an empty
     * array.
     *
     * @return void
     */
    public function testPaginationInformationForNonPaginatorReturnsEmptyArray(): void
    {
        $collection = new ReportCollection(collect([]), SalesSummaryResource::class);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->paginationInformation($request, [], []);

        self::assertSame([], $result);
    }

    /**
     * Test that paginationInformation reports continue correctly.
     *
     * @return void
     */
    public function testPaginationInformationReportsContinueCorrectly(): void
    {
        $items = [new SalesSummaryRow('2026-01', 1, '1.00')];

        $hasMore = new LengthAwarePaginator($items, 50, 15, 1, [
            'path' => self::PAGINATION_PATH,
        ]);

        $collectionMore = new ReportCollection($hasMore, SalesSummaryResource::class);
        $resultMore     = $collectionMore->paginationInformation(Request::create('/', HttpMethod::GET->getVerb()), [], []);

        self::assertTrue($resultMore['meta']['continue']);

        $noMore = new LengthAwarePaginator($items, 1, 15, 1, [
            'path' => self::PAGINATION_PATH,
        ]);

        $collectionLast = new ReportCollection($noMore, SalesSummaryResource::class);
        $resultLast     = $collectionLast->paginationInformation(Request::create('/', HttpMethod::GET->getVerb()), [], []);

        self::assertFalse($resultLast['meta']['continue']);
    }

    /**
     * Test that pagination links point at the correct page numbers.
     *
     * @return void
     */
    public function testPaginationLinksPointAtCorrectPages(): void
    {
        $items     = [new SalesSummaryRow('2026-01', 1, '1.00')];
        $paginator = new LengthAwarePaginator($items, 50, 15, 2, [
            'path' => self::PAGINATION_PATH,
        ]);

        $collection = new ReportCollection($paginator, SalesSummaryResource::class);

        $request = Request::create('/', HttpMethod::GET->getVerb());
        $result  = $collection->paginationInformation($request, [], []);

        self::assertSame(self::PAGINATION_PATH . '?page=1', $result['links']['first']);
        self::assertSame(self::PAGINATION_PATH . '?page=2', $result['links']['self']);
        self::assertSame(self::PAGINATION_PATH . '?page=4', $result['links']['last']);
    }
}
