<?php

declare(strict_types = 1);

namespace Tests\Unit\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Search\SearchPlan;
use Tests\Fixtures\Resources\SearchableUserResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the SearchPlan compiled search plan.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SearchPlan::class)]
final class SearchPlanTest extends TestCase
{
    /**
     * Reset both static caches before each test to avoid cross-test bleed.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SearchPlan::clearCache();
        SchemaCompiler::clearCache();
    }

    /**
     * Reset both static caches after each test to avoid cross-test bleed.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        SearchPlan::clearCache();
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that a plan carries every declared column keyed to the strategy it
     * was declared with.
     *
     * @return void
     */
    public function testCarriesEveryDeclaredColumn(): void
    {
        $plan = SearchPlan::build($this->makeSchema());

        self::assertSame(['id', 'name', 'email'], $plan->columns());
        self::assertFalse($plan->isEmpty());
    }

    /**
     * Test that the distinct strategies are reported in declaration order,
     * without repeating one shared by two columns.
     *
     * @return void
     */
    public function testReportsDistinctStrategiesInDeclarationOrder(): void
    {
        $plan = SearchPlan::build($this->makeSchema());

        self::assertSame([SearchStrategy::EXACT, SearchStrategy::SUBSTRING], $plan->strategies());
    }

    /**
     * Test that the columns are grouped by the strategy they were declared
     * with, so a driver applies one predicate shape per group.
     *
     * @return void
     */
    public function testGroupsColumnsByStrategy(): void
    {
        $plan = SearchPlan::build($this->makeSchema());

        self::assertSame(['id'], $plan->columnsFor(SearchStrategy::EXACT));
        self::assertSame(['name', 'email'], $plan->columnsFor(SearchStrategy::SUBSTRING));
    }

    /**
     * Test that a strategy nobody declared yields no columns rather than
     * falling back to the declared set.
     *
     * @return void
     */
    public function testUndeclaredStrategyYieldsNoColumns(): void
    {
        $plan = SearchPlan::build($this->makeSchema());

        self::assertSame([], $plan->columnsFor(SearchStrategy::PREFIX));
    }

    /**
     * Test that a schema declaring nothing searchable yields an empty plan
     * rather than a plan over every column.
     *
     * @return void
     */
    public function testSchemaWithNoDeclarationYieldsAnEmptyPlan(): void
    {
        $plan = SearchPlan::build(new CompiledSchema([], []));

        self::assertTrue($plan->isEmpty());
        self::assertSame([], $plan->columns());
        self::assertSame([], $plan->strategies());
    }

    /**
     * Test that a plan built for a resource class reads that resource's own
     * declarations.
     *
     * @return void
     */
    public function testBuildsThePlanForAResourceClass(): void
    {
        $plan = SearchPlan::for(SearchableUserResource::class);

        self::assertSame(['id', 'name', 'email'], $plan->columns());
        self::assertSame(['name', 'email'], $plan->columnsFor(SearchStrategy::SUBSTRING));
    }

    /**
     * Test that a resource declaring no searchable field yields an empty plan,
     * so search is served only where a schema asked for it.
     *
     * @return void
     */
    public function testResourceWithoutDeclarationsYieldsAnEmptyPlan(): void
    {
        self::assertTrue(SearchPlan::for(UserResource::class)->isEmpty());
    }

    /**
     * Test that the plan for a resource class is memoised rather than rebuilt
     * on every request.
     *
     * @return void
     */
    public function testMemoisesThePlanPerResourceClass(): void
    {
        self::assertSame(
            SearchPlan::for(SearchableUserResource::class),
            SearchPlan::for(SearchableUserResource::class),
        );
    }

    /**
     * Test that clearing the cache forces the next call to rebuild the plan.
     *
     * @return void
     */
    public function testClearCacheForcesARebuild(): void
    {
        $first = SearchPlan::for(SearchableUserResource::class);

        SearchPlan::clearCache();

        self::assertNotSame($first, SearchPlan::for(SearchableUserResource::class));
    }

    /**
     * Build a compiled schema declaring one exact and two substring columns.
     *
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function makeSchema(): CompiledSchema
    {
        return new CompiledSchema([], [], [], [], [], [], [
            'id'    => SearchStrategy::EXACT,
            'name'  => SearchStrategy::SUBSTRING,
            'email' => SearchStrategy::SUBSTRING,
        ]);
    }
}
