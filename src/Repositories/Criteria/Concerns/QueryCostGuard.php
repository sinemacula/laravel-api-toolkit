<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Query\QueryCostLimits;

/**
 * Enforces the flat query-cost caps for a criteria application.
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
    }

    /**
     * Count the relation aggregates the request asks for.
     *
     * @param  string|null  $resourceType
     * @return int
     */
    private function countAggregates(?string $resourceType): int
    {
        return $resourceType === null ? 0 : count(ApiQuery::getAggregates($resourceType) ?? []);
    }
}
