<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Query\PageSize;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\QueryCostGuard;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Resources\CappedPageUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Resources\V1\UserResource as VersionedUserResource;
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

        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class, OrganizationResource::class]);

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

        $this->guard->guard(PageSize::for(null));
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
     * Test that the relevance key counts toward the sort key cap.
     *
     * @return void
     */
    public function testRelevanceKeyCountsTowardTheSortKeyCap(): void
    {
        Config::set('api-toolkit.query_cost.max_order_keys', 2);

        $this->parseQuery(['search' => 'smith', 'order' => 'relevance,name,email']);

        $this->assertRejectedForCost('order', QueryCostLimits::MAX_ORDER_KEYS, 2, 3);
    }

    /**
     * Test that exactly the aggregate cap is accepted, counting each requested
     * key once though two resources carry its type.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testExactlyTheAggregateCapIsAccepted(): void
    {
        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class, VersionedUserResource::class]);
        Config::set('api-toolkit.query_cost.max_aggregates', 2);

        $this->parseQuery([
            'aggregates' => [self::RESOURCE_TYPE => 'posts_count,posts_sum_id'],
        ]);

        $this->expectNotToPerformAssertions();

        $this->guard->guard(PageSize::for(null));
    }

    /**
     * Test that one aggregate key too many is rejected.
     *
     * @return void
     */
    public function testOneAggregateTooManyIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_aggregates', 2);

        $this->parseQuery([
            'aggregates' => [self::RESOURCE_TYPE => 'posts_count,posts_sum_id,posts_avg_id'],
        ]);

        $this->assertRejectedForCost('aggregates', QueryCostLimits::MAX_AGGREGATES, 2, 3);
    }

    /**
     * Test that the keys of every resource type are charged against one cap.
     *
     * @return void
     */
    public function testAggregatesOfEveryTypeAreChargedTogether(): void
    {
        Config::set('api-toolkit.query_cost.max_aggregates', 2);

        $this->parseQuery([
            'aggregates' => [
                self::RESOURCE_TYPE => 'posts_count,posts_sum_id',
                'organizations'     => 'users_count',
            ],
        ]);

        $this->assertRejectedForCost('aggregates', QueryCostLimits::MAX_AGGREGATES, 2, 3);
    }

    /**
     * Test that a page size above the resource's ceiling is refused before the
     * sort-key and aggregate caps.
     *
     * @return void
     */
    public function testPageSizeAboveTheResourceCeilingIsRefusedBeforeTheOtherCaps(): void
    {
        Config::set('api-toolkit.query_cost.max_order_keys', 1);
        Config::set('api-toolkit.query_cost.max_aggregates', 1);

        $this->parseQuery([
            'limit'      => '3',
            'order'      => 'name:asc,email:desc',
            'aggregates' => [self::RESOURCE_TYPE => 'posts_count,posts_sum_id'],
        ]);

        $this->assertRejectedForCost('limit', 'max_limit', 2, 3, CappedPageUserResource::class);
    }

    /**
     * Test that a cursor read is held to the resource's ceiling too.
     *
     * @return void
     */
    public function testCursorReadIsHeldToTheResourceCeiling(): void
    {
        $this->parseQuery(['limit' => '3', 'pagination' => 'cursor']);

        $this->assertRejectedForCost('limit', 'max_limit', 2, 3, CappedPageUserResource::class);
    }

    /**
     * Test that a page size at the resource's ceiling, or none, is accepted.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testPageSizeWithinTheResourceCeilingIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        $this->parseQuery(['limit' => '2']);
        $this->guard->guard(PageSize::for(CappedPageUserResource::class));

        $this->parseQuery([]);
        $this->guard->guard(PageSize::for(CappedPageUserResource::class));
    }

    /**
     * Test that a page past the offset cap is left to the paginator, so a read
     * that does not page is not refused.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testPagePastTheOffsetCapIsNotRefused(): void
    {
        Config::set('api-toolkit.query_cost.max_offset', 100);

        $this->parseQuery(['page' => '12', 'limit' => '10']);

        $this->expectNotToPerformAssertions();

        $this->guard->guard(PageSize::for(null));
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

        $this->guard->guard(PageSize::for(null));
    }

    /**
     * Assert that the parsed query is rejected on cost with the given details.
     *
     * @param  string  $parameter
     * @param  string  $reason
     * @param  int  $limit
     * @param  int  $actual
     * @param  string|null  $resource
     * @return void
     */
    private function assertRejectedForCost(string $parameter, string $reason, int $limit, int $actual, ?string $resource = null): void
    {
        try {
            $this->guard->guard(PageSize::for($resource));

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
