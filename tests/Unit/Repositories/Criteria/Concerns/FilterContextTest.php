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
     * Test that the root sits at the top of the pointer and the parameter.
     *
     * @return void
     */
    public function testRootSitsAtTheTopOfThePointerAndTheParameter(): void
    {
        $root = FilterContext::root();

        self::assertSame('', $root->pointer());
        self::assertSame('/name', $root->pointerTo('name'));
        self::assertSame('filters', $root->path());
    }

    /**
     * Test that descending records the position in the pointer and the path.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDescendRecordsThePosition(): void
    {
        $child = FilterContext::root()->descend('$or')->at('1')->descend('posts');

        self::assertSame('/$or/1/posts', $child->pointer());
        self::assertSame('/$or/1/posts/name', $child->pointerTo('name'));
        self::assertSame('filters.$or.1.posts', $child->path());
        self::assertSame('filters.$or.1.posts.name', $child->at('name')->path());
    }

    /**
     * Test that a key holding a pointer delimiter is escaped in the pointer
     * only.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAKeyHoldingAPointerDelimiterIsEscapedInThePointer(): void
    {
        $context = FilterContext::root()->descend('a/b')->at('c~d');

        self::assertSame('/a~1b/c~0d/~01', $context->pointerTo('~1'));
        self::assertSame('filters.a/b.c~d', $context->path());
    }

    /**
     * Test that entering a segment keeps the level in effect.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testEnteringASegmentMovesThePositionWithoutSpendingALevel(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', 2);

        $context = FilterContext::root(new FilterCostBudget(QueryCostLimits::fromConfig()))
            ->descend('posts')
            ->at('$has');

        self::assertSame('/posts/$has/name', $context->pointerTo('name'));
        self::assertSame('filters.posts.$has', $context->path());

        try {
            $context->descend('tags')->descend('country');

            self::fail('Expected a rejection for a descent beyond the depth cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => 'filters',
                'pointer'   => '/posts/$has/tags/country',
                'reason'    => QueryCostLimits::MAX_DEPTH,
                'limit'     => 2,
                'actual'    => 3,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Test that entering a segment carries the budget.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testEnteringASegmentCarriesTheBudget(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 1);

        $root = FilterContext::root(new FilterCostBudget(QueryCostLimits::fromConfig()));

        $root->admit('name');

        try {
            $root->at('$has')->admit('posts');

            self::fail('Expected a rejection for a node beyond the node cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => 'filters',
                'pointer'   => '/$has/posts',
                'reason'    => QueryCostLimits::MAX_NODES,
                'limit'     => 1,
                'actual'    => 2,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Test that a context without a budget enforces nothing.
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

        $context = FilterContext::root()->descend('posts')->descend('tags');

        $context->admit('name');
        $context->admit('title');
    }

    /**
     * Test that a descent beyond the depth cap is refused by the root's budget.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testDescendBeyondTheDepthCapIsRefused(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', 2);

        $context = FilterContext::root(new FilterCostBudget(QueryCostLimits::fromConfig()))->descend('posts');

        try {
            $context->descend('tags')->descend('country');

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
     * Test that the budget is shared by every derived level.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testTheBudgetIsSharedByEveryDerivedLevel(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 2);

        $root  = FilterContext::root(new FilterCostBudget(QueryCostLimits::fromConfig()));
        $child = $root->descend('posts');

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
}
