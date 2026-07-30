<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\ReportCollection;
use SineMacula\ApiToolkit\Http\Resources\ReportResource;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Reports\SalesSummaryResource;
use Tests\Fixtures\Reports\SalesSummaryRow;
use Tests\TestCase;

/**
 * Feature tests for report read-model serialization through the HTTP kernel.
 *
 * A paginated report of DTO rows is returned from a real route and asserted to
 * inherit the identical envelope as an entity collection - `data`, `meta`,
 * `links`, and the `Total-Count` header - which only a real dispatch confirms
 * because the pagination hooks fire through the response, not `toArray()`.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ReportResource::class)]
#[CoversClass(ReportCollection::class)]
final class ReportCollectionTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with paginated and single report routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::get('/reports/sales', static function (): ReportCollection {

            $rows = [
                new SalesSummaryRow('2026-06', 120, '15000.00'),
                new SalesSummaryRow('2026-07', 98, '12250.00'),
            ];

            /** @var \SineMacula\ApiToolkit\Http\Resources\ReportCollection */
            return SalesSummaryResource::collection(new LengthAwarePaginator($rows, 2, 15, 1));
        });

        Route::get('/reports/sales/first', static fn (): SalesSummaryResource => new SalesSummaryResource(new SalesSummaryRow('2026-06', 120, '15000.00')));
    }

    /**
     * Test that a paginated report collection renders the toolkit envelope with
     * the Total-Count header and per-row discriminator.
     *
     * @return void
     */
    public function testPaginatedReportRendersTheToolkitEnvelope(): void
    {
        $response = $this->getJson('/reports/sales');

        $response->assertOk();
        $response->assertJsonStructure(['data', 'meta', 'links']);
        $response->assertJsonPath('data.0._type', 'sales-summary');
        $response->assertJsonPath('data.0.month', '2026-06');
        $response->assertJsonPath('data.0.orders', 120);
        $response->assertJsonPath('meta.total', 2);
        $response->assertHeader('Total-Count', '2');
    }

    /**
     * Test that a single report resource renders the data-wrapped item with its
     * discriminator.
     *
     * @return void
     */
    public function testSingleReportRendersTheDataWrappedItem(): void
    {
        $response = $this->getJson('/reports/sales/first');

        $response->assertOk();
        $response->assertJsonPath('data._type', 'sales-summary');
        $response->assertJsonPath('data.month', '2026-06');
    }
}
