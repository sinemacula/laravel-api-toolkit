<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Reports;

/**
 * Fixture aggregated read-model row (a report DTO, not an Eloquent model).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SalesSummaryRow
{
    /**
     * Create a new sales summary row.
     *
     * @param  string  $month
     * @param  int  $orders
     * @param  string  $revenue
     */
    public function __construct(

        /** The reporting period in YYYY-MM form. */
        public string $month,

        /** The number of orders in the period. */
        public int $orders,

        /** The summed revenue for the period. */
        public string $revenue,
    ) {}
}
