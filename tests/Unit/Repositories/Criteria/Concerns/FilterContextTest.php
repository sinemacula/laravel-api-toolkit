<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterCostBudget;
use Tests\TestCase;

/**
 * Tests for the FilterContext value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterContext::class)]
final class FilterContextTest extends TestCase
{
    /**
     * Test that root returns a null logical operator at the top level.
     *
     * @return void
     */
    public function testRootReturnsNullOperator(): void
    {
        $root = FilterContext::root();

        self::assertNull($root->getLogicalOperator());
        self::assertSame(0, $root->getDepth());
        self::assertSame('/name', $root->pointerTo('name'));
    }

    /**
     * Test that nested sets the logical operator.
     *
     * @return void
     */
    public function testNestedSetsOperator(): void
    {
        $nested = FilterContext::nested('$and');

        self::assertSame('$and', $nested->getLogicalOperator());
        self::assertSame(1, $nested->getDepth());
        self::assertSame('/$and/name', $nested->pointerTo('name'));
    }

    /**
     * Test that descending records the level, the position, and the operator
     * asked for rather than the one in effect above it.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDescendRecordsTheLevelPositionAndOperator(): void
    {
        $child = FilterContext::root()->descend('posts', '$or')->descend('tags', null);

        self::assertNull($child->getLogicalOperator());
        self::assertSame(2, $child->getDepth());
        self::assertSame('/posts/tags/name', $child->pointerTo('name'));
    }

    /**
     * Test that a context built without a budget enforces nothing, so the value
     * object stays usable outside a governed walk.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testContextWithoutABudgetIsUncapped(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', 1);
        Config::set('api-toolkit.query_cost.max_nodes', 1);

        $this->expectNotToPerformAssertions();

        $context = FilterContext::root()->descend('posts', null)->descend('tags', null);

        $context->admit('name');
        $context->admit('title');
    }

    /**
     * Test that a descent beyond the depth cap is refused by the budget the
     * root was given, pointing at the level it was refused at.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDescendBeyondTheDepthCapIsRefused(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', 2);

        $context = FilterContext::root(new FilterCostBudget(QueryCostLimits::fromConfig()))->descend('posts', null);

        try {
            $context->descend('tags', null)->descend('country', null);

            self::fail('Expected a rejection for a descent beyond the depth cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => 'filters',
                'pointer'   => '/posts/tags/country',
                'reason'    => QueryCostLimits::MAX_DEPTH,
                'limit'     => 2,
                'actual'    => 3,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Test that the budget is shared by every derived level, so nodes admitted
     * at one level count against those admitted at another.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testTheBudgetIsSharedByEveryDerivedLevel(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 2);

        $root  = FilterContext::root(new FilterCostBudget(QueryCostLimits::fromConfig()));
        $child = $root->descend('posts', null);

        $root->admit('name');
        $child->admit('title');

        try {
            $root->admit('email');

            self::fail('Expected a rejection for a node beyond the node cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => 'filters',
                'pointer'   => '/email',
                'reason'    => QueryCostLimits::MAX_NODES,
                'limit'     => 2,
                'actual'    => 3,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Test that the OR connective is resolved from the current operator.
     *
     * @return void
     */
    public function testOrConnectiveIsResolvedFromTheCurrentOperator(): void
    {
        self::assertTrue(FilterContext::nested('$or')->isOr());
        self::assertSame('or', FilterContext::nested('$or')->sqlBoolean());
        self::assertFalse(FilterContext::nested('$and')->isOr());
        self::assertSame('and', FilterContext::nested('$and')->sqlBoolean());
    }
}
