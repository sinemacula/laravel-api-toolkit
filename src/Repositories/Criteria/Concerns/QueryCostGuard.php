<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Query\QueryCostLimits;

/**
 * Enforces the flat query-cost caps for a criteria application.
 *
 * Covers the parameters whose cost is a plain count rather than a tree: the
 * sort columns, the relation aggregates (counts, sums, and averages together,
 * since each adds its own correlated subquery), and the requested page number.
 * A page beyond the offset cap is rejected outright rather than clamped, since
 * silently answering a different page than the one asked for would misreport
 * the position within the result set.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QueryCostGuard
{
    /**
     * Reject the request when it exceeds one of the flat caps.
     *
     * @param  string|null  $resourceType
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function guard(?string $resourceType): void
    {
        $limits = QueryCostLimits::fromConfig();

        $limits->enforce(QueryCostLimits::MAX_ORDER_KEYS, count(ApiQuery::getOrder()), 'order');
        $limits->enforce(QueryCostLimits::MAX_AGGREGATES, $this->countAggregates($resourceType), 'aggregates');
        $limits->enforce(QueryCostLimits::MAX_OFFSET, ApiQuery::getPage() ?? 1, 'page');
    }

    /**
     * Count the relation aggregates the request asks for.
     *
     * @param  string|null  $resourceType
     * @return int
     */
    private function countAggregates(?string $resourceType): int
    {
        return count(ApiQuery::getCounts($resourceType) ?? [])
            + $this->countExpressions(ApiQuery::getSums($resourceType))
            + $this->countExpressions(ApiQuery::getAverages($resourceType));
    }

    /**
     * Count the aggregate expressions across a relation-keyed column map, where
     * each relation may carry more than one column.
     *
     * @param  array<string, mixed>|null  $aggregates
     * @return int
     */
    private function countExpressions(?array $aggregates): int
    {
        $total = 0;

        foreach ($aggregates ?? [] as $columns) {
            $total += is_array($columns) ? count($columns) : 1;
        }

        return $total;
    }
}
