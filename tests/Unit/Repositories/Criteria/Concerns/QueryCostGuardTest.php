<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\QueryCostGuard;
use SineMacula\Http\Enums\HttpMethod;
use Tests\TestCase;

/**
 * Tests for the QueryCostGuard concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryCostGuard::class)]
final class QueryCostGuardTest extends TestCase
{
    /** @var string The resource type the aggregates are requested for */
    private const string RESOURCE_TYPE = 'users';

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\QueryCostGuard */
    private QueryCostGuard $guard;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new QueryCostGuard;
    }

    /**
     * Test that exactly the sort-key cap is accepted.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testExactlyTheSortKeyCapIsAccepted(): void
    {
        Config::set('api-toolkit.query_cost.max_order_keys', 3);

        $this->parseQuery(['order' => 'name:asc,email:desc,id:asc']);

        $this->expectNotToPerformAssertions();

        $this->guard->guard(self::RESOURCE_TYPE);
    }

    /**
     * Test that one sort key too many is rejected.
     *
     * @return void
     */
    public function testOneSortKeyTooManyIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_order_keys', 3);

        $this->parseQuery(['order' => 'name:asc,email:desc,id:asc,status:desc']);

        $this->assertRejectedForCost('order', QueryCostLimits::MAX_ORDER_KEYS, 3, 4);
    }

    /**
     * Test that exactly the aggregate cap is accepted, counting each requested
     * column of a relation separately.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testExactlyTheAggregateCapIsAccepted(): void
    {
        Config::set('api-toolkit.query_cost.max_aggregates', 4);

        $this->parseQuery([
            'counts' => [self::RESOURCE_TYPE => 'posts,comments'],
            'sums'   => [self::RESOURCE_TYPE => ['posts' => 'id,views']],
        ]);

        $this->expectNotToPerformAssertions();

        $this->guard->guard(self::RESOURCE_TYPE);
    }

    /**
     * Test that one aggregate too many is rejected, counting counts, sums, and
     * averages together.
     *
     * @return void
     */
    public function testOneAggregateTooManyIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_aggregates', 4);

        $this->parseQuery([
            'counts'   => [self::RESOURCE_TYPE => 'posts,comments'],
            'sums'     => [self::RESOURCE_TYPE => ['posts' => 'id,views']],
            'averages' => [self::RESOURCE_TYPE => ['posts' => 'id']],
        ]);

        $this->assertRejectedForCost('aggregates', QueryCostLimits::MAX_AGGREGATES, 4, 5);
    }

    /**
     * Test that an aggregate whose columns are not a list counts as a single
     * expression rather than raising a type error.
     *
     * @return void
     */
    public function testScalarAggregateColumnsCountAsOneExpression(): void
    {
        Config::set('api-toolkit.query_cost.max_aggregates', 1);

        ApiQuery::shouldReceive('getOrder')->andReturn([]);
        ApiQuery::shouldReceive('getCounts')->andReturn(null);
        ApiQuery::shouldReceive('getSums')->andReturn(['posts' => 'id']);
        ApiQuery::shouldReceive('getAverages')->andReturn(['posts' => 'id']);
        ApiQuery::shouldReceive('getPage')->andReturn(1);

        $this->assertRejectedForCost('aggregates', QueryCostLimits::MAX_AGGREGATES, 1, 2);
    }

    /**
     * Test that exactly the offset cap is accepted.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testExactlyTheOffsetCapIsAccepted(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 10);

        $this->parseQuery(['page' => '10']);

        $this->expectNotToPerformAssertions();

        $this->guard->guard(self::RESOURCE_TYPE);
    }

    /**
     * Test that a page one beyond the offset cap is rejected outright rather
     * than being clamped to the last page within the cap.
     *
     * @return void
     */
    public function testPageBeyondTheOffsetCapIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 10);

        $this->parseQuery(['page' => '11']);

        $this->assertRejectedForCost('page', QueryCostLimits::MAX_OFFSET, 10, 11);

        self::assertSame(11, ApiQuery::getPage());
    }

    /**
     * Test that a request carrying none of the capped parameters is accepted.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRequestWithoutCappedParametersIsAccepted(): void
    {
        $this->parseQuery([]);

        $this->expectNotToPerformAssertions();

        $this->guard->guard(null);
    }

    /**
     * Assert that the parsed query is rejected on cost, carrying the parameter
     * at fault, the cap that rejected it, and both sides of the comparison.
     *
     * @param  string  $parameter
     * @param  string  $reason
     * @param  int  $limit
     * @param  int  $actual
     * @return void
     */
    private function assertRejectedForCost(string $parameter, string $reason, int $limit, int $actual): void
    {
        try {
            $this->guard->guard(self::RESOURCE_TYPE);

            self::fail('Expected a rejection for the "' . $reason . '" cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => $parameter,
                'pointer'   => '',
                'reason'    => $reason,
                'limit'     => $limit,
                'actual'    => $actual,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Parse the given query parameters through the ApiQuery facade.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     */
    private function parseQuery(array $parameters): void
    {
        ApiQuery::parse(Request::create('/test', HttpMethod::GET->getVerb(), $parameters));
    }
}
