<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Query\QueryCostLimits;

/**
 * Enforces the flat query-cost caps for a criteria application.
 *
 * A page past the offset cap is rejected, as clamping would serve another page.
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

        $this->guardOffset($limits);
    }

    /**
     * Reject a page whose offset the read would have to scan past.
     *
     * A cursor seeks to its position, so a cursor page carries no offset cost.
     *
     * @param  \SineMacula\ApiToolkit\Query\QueryCostLimits  $limits
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function guardOffset(QueryCostLimits $limits): void
    {
        $ceiling = $limits->limit(QueryCostLimits::MAX_OFFSET);
        $page    = ApiQuery::getPage();

        if ($ceiling <= 0 || $page === null || ApiQuery::isCursorPaginated()) {
            return;
        }

        $size   = ApiQuery::getResolvedLimit();
        $offset = ($page - 1) * $size;

        if ($offset <= $ceiling) {
            return;
        }

        throw QueryTooExpensiveException::exceeded('page', '', QueryCostLimits::MAX_OFFSET, intdiv($ceiling, $size) + 1, $page);
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
     * Count the aggregate expressions across a relation-keyed column map.
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
