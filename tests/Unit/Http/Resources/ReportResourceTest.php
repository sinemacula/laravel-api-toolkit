<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\ReportCollection;
use SineMacula\ApiToolkit\Http\Resources\ReportResource;
use SineMacula\ApiToolkit\Http\Resources\ToolkitResource;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Reports\SalesSummaryResource;
use Tests\Fixtures\Reports\SalesSummaryRow;
use Tests\TestCase;

/**
 * Tests for the report resource base and the shared toolkit resource base.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ReportResource::class)]
#[CoversClass(ToolkitResource::class)]
final class ReportResourceTest extends TestCase
{
    /**
     * Test that toArray stamps the _type discriminator and transforms the DTO.
     *
     * @return void
     */
    public function testToArrayStampsTypeAndTransformsDto(): void
    {
        $row = new SalesSummaryRow('2026-01', 42, '4821.00');

        $result = (new SalesSummaryResource($row))->toArray(Request::create('/', HttpMethod::GET->getVerb()));

        self::assertSame('sales-summary', $result['_type']);
        self::assertSame('2026-01', $result['month']);
        self::assertSame(42, $result['orders']);
        self::assertSame('4821.00', $result['revenue']);
    }

    /**
     * Test that the _type discriminator is the first key in the payload.
     *
     * @return void
     */
    public function testTypeDiscriminatorIsPrependedFirst(): void
    {
        $row = new SalesSummaryRow('2026-02', 7, '99.99');

        $result = (new SalesSummaryResource($row))->toArray(Request::create('/', HttpMethod::GET->getVerb()));

        self::assertSame('_type', array_key_first($result));
    }

    /**
     * Test that the collection factory yields a ReportCollection so paginated
     * reports inherit the shared envelope.
     *
     * @return void
     */
    public function testCollectionFactoryReturnsReportCollection(): void
    {
        $rows = collect([
            new SalesSummaryRow('2026-01', 1, '1.00'),
            new SalesSummaryRow('2026-02', 2, '2.00'),
        ]);

        $collection = SalesSummaryResource::collection($rows);

        self::assertInstanceOf(ReportCollection::class, $collection);
    }
}
