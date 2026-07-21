<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\ReportCollection;
use SineMacula\ApiToolkit\Http\Resources\ToolkitCollection;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Reports\SalesSummaryResource;
use Tests\Fixtures\Reports\SalesSummaryRow;
use Tests\TestCase;

/**
 * Tests for the shared toolkit resource collection base.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ToolkitCollection::class)]
final class ToolkitCollectionTest extends TestCase
{
    /**
     * Test that toResponse returns the native envelope, without the export
     * negotiation Vary header, when the exporter is not bound.
     *
     * @return void
     */
    public function testToResponseFallsBackToNativeEnvelopeWhenExporterUnbound(): void
    {
        self::assertFalse(App::bound(ExportNegotiator::class));

        $rows = collect([
            new SalesSummaryRow('2026-01', 5, '50.00'),
            new SalesSummaryRow('2026-02', 9, '90.00'),
        ]);

        $collection = new ReportCollection($rows, SalesSummaryResource::class);

        $response = $collection->toResponse(Request::create('/', HttpMethod::GET->getVerb()));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->headers->has('Vary'));
    }
}
