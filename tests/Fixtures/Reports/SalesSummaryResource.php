<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Reports;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\ReportResource;
use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesSchema;

/**
 * Fixture report resource that transforms a SalesSummaryRow DTO and describes
 * the row it renders.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SalesSummaryResource extends ReportResource implements DefinesSchema
{
    /** @var string The report discriminator exposed under the `_type` key */
    public const string REPORT_TYPE = 'sales-summary';

    /**
     * Return the JSON Schema of a rendered row.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                '_type'   => ['const' => self::REPORT_TYPE],
                'month'   => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}$'],
                'orders'  => ['type' => 'integer', 'minimum' => 0],
                'revenue' => ['type' => 'string'],
            ],
            'required' => ['_type', 'month', 'orders', 'revenue'],
        ];
    }

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
