<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Reports;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\ReportResource;

/**
 * Fixture report resource that transforms a SalesSummaryRow DTO.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SalesSummaryResource extends ReportResource
{
    /** @var string The report discriminator exposed under the `_type` key */
    public const string REPORT_TYPE = 'sales-summary';

    /**
     * Transform the report row into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var \Tests\Fixtures\Reports\SalesSummaryRow $row */
        $row = $this->resource;

        return $this->withType(self::REPORT_TYPE, [
            'month'   => $row->month,
            'orders'  => $row->orders,
            'revenue' => $row->revenue,
        ]);
    }
}
