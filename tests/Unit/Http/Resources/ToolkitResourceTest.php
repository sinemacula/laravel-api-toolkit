<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\ToolkitResource;
use SineMacula\Exporter\Http\ExportNegotiator;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Reports\SalesSummaryResource;
use Tests\Fixtures\Reports\SalesSummaryRow;
use Tests\TestCase;

/**
 * Tests for the shared toolkit single-item resource base.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ToolkitResource::class)]
final class ToolkitResourceTest extends TestCase
{
    /**
     * Test that toResponse returns the native JSON response, without the export
     * negotiation Vary header, when the exporter is not bound.
     *
     * @return void
     */
    public function testToResponseFallsBackToNativeResponseWhenExporterUnbound(): void
    {
        self::assertFalse(App::bound(ExportNegotiator::class));

        $resource = new SalesSummaryResource(new SalesSummaryRow('2026-01', 42, '4821.00'));

        $response = $resource->toResponse(Request::create('/', HttpMethod::GET->getVerb()));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->headers->has('Vary'));
    }
}
