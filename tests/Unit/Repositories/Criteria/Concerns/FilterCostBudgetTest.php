<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterCostBudget;
use Tests\TestCase;

/**
 * Tests for the FilterCostBudget.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterCostBudget::class)]
final class FilterCostBudgetTest extends TestCase
{
    /**
     * Test that a descent to exactly the depth cap is admitted.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDescentToExactlyTheDepthCapIsAdmitted(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', 3);

        $this->expectNotToPerformAssertions();

        $this->budget()->admitLevel(3, '/$or');
    }

    /**
     * Test that a descent one level beyond the depth cap is refused, naming the
     * level it was refused at.
     *
     * @return void
     */
    public function testDescentBeyondTheDepthCapIsRefused(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', 3);

        try {
            $this->budget()->admitLevel(4, '/$or/posts/$and/tags');

            self::fail('Expected a rejection for a descent beyond the depth cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => 'filters',
                'pointer'   => '/$or/posts/$and/tags',
                'reason'    => QueryCostLimits::MAX_DEPTH,
                'limit'     => 3,
                'actual'    => 4,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Test that exactly the node cap is admitted across successive levels,
     * proving the running total is shared rather than reset per level.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testExactlyTheNodeCapIsAdmitted(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 3);

        $this->expectNotToPerformAssertions();

        $budget = $this->budget();

        $budget->admitNode('/name');
        $budget->admitNode('/email');
        $budget->admitNode('/$or/status');
    }

    /**
     * Test that one node beyond the cap is refused, reporting the running total
     * rather than the count at a single level.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNodeBeyondTheCapIsRefused(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 3);

        $budget = $this->budget();

        $budget->admitNode('/name');
        $budget->admitNode('/email');
        $budget->admitNode('/$or/status');

        try {
            $budget->admitNode('/$or/id');

            self::fail('Expected a rejection for a node beyond the node cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => 'filters',
                'pointer'   => '/$or/id',
                'reason'    => QueryCostLimits::MAX_NODES,
                'limit'     => 3,
                'actual'    => 4,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Build a budget from the configured caps.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterCostBudget
     */
    private function budget(): FilterCostBudget
    {
        return new FilterCostBudget(QueryCostLimits::fromConfig());
    }
}
