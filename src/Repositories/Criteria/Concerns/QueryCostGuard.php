<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ResourceTypeIndex;
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
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function guard(): void
    {
        $limits = QueryCostLimits::fromConfig();

        $limits->enforce(QueryCostLimits::MAX_ORDER_KEYS, count(ApiQuery::getOrder()), 'order');
        $limits->enforce(QueryCostLimits::MAX_AGGREGATES, $this->countAggregates(), 'aggregates');
    }

    /**
     * Count the relation aggregates the request asks for across every resource
     * type, rendered or not.
     *
     * The parser refuses a type no catalogued resource carries, so the
     * catalogue names every type requested.
     *
     * @return int
     */
    private function countAggregates(): int
    {
        $types = array_unique(array_map(static fn (string $resource): string => $resource::getResourceType(), ResourceTypeIndex::all()));

        return array_sum(array_map(static fn (string $type): int => count(ApiQuery::getAggregates($type) ?? []), $types));
    }
}
