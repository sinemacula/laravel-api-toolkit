<?php

declare(strict_types = 1);

namespace Tests\Unit\Search;

use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Search\RelevanceOrder;
use SineMacula\ApiToolkit\Search\SearchPlan;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Concerns\AssertsBoundPlaceholders;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Models\Device;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the RelevanceOrder ranking builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RelevanceOrder::class)]
final class RelevanceOrderTest extends TestCase
{
    use AssertsBoundPlaceholders;

    /** @var string The term every test ranks by */
    private const string TERM = 'smith';

    /**
     * Test that a plan of equality columns alone adds no order, since every row
     * it matches matches the whole value.
     *
     * @return void
     */
    public function testEqualityOnlyPlanAddsNoOrder(): void
    {
        $query = $this->rank(['name' => SearchStrategy::EXACT, 'email' => SearchStrategy::EXACT]);

        self::assertNull($query->getQuery()->orders);
        self::assertSame([], $query->getQuery()->getRawBindings()['order']);
    }

    /**
     * Test that a prefix column ranks its whole value ahead of its start.
     *
     * @return void
     */
    public function testPrefixColumnRanksTheWholeValueThenTheStart(): void
    {
        $query = $this->rank(['email' => SearchStrategy::PREFIX]);

        $this->assertRanked(
            $query,
            'case when "users"."email" like ? escape \'!\' then 0 when "users"."email" like ? escape \'!\' then 1 else 3 end',
            ['smith', 'smith%'],
        );
    }

    /**
     * Test that an anywhere-match column also ranks the start of a word.
     *
     * @return void
     */
    public function testAnywhereColumnRanksTheWholeValueTheStartAndTheStartOfAWord(): void
    {
        $query = $this->rank(['name' => SearchStrategy::SUBSTRING]);

        $this->assertRanked(
            $query,
            'case when "users"."name" like ? escape \'!\' then 0 when "users"."name" like ? escape \'!\' then 1 '
            . 'when "users"."name" like ? escape \'!\' then 2 else 3 end',
            ['smith', 'smith%', '% smith%'],
        );
    }

    /**
     * Test that each tier ORs every column reaching it, comparing an equality
     * column with "=".
     *
     * @return void
     */
    public function testEachTierOrsTheColumnsReachingIt(): void
    {
        $query = $this->rank(['name' => SearchStrategy::EXACT, 'email' => SearchStrategy::PREFIX]);

        $this->assertRanked(
            $query,
            'case when "users"."name" = ? or "users"."email" like ? escape \'!\' then 0 '
            . 'when "users"."email" like ? escape \'!\' then 1 else 3 end',
            ['smith', 'smith', 'smith%'],
        );
    }

    /**
     * Test that the given operator is rendered as given.
     *
     * @return void
     */
    public function testRendersTheOperatorVerbatim(): void
    {
        $query = $this->rank(['email' => SearchStrategy::PREFIX], 'ilike');

        $this->assertRanked(
            $query,
            'case when "users"."email" ilike ? escape \'!\' then 0 when "users"."email" ilike ? escape \'!\' then 1 else 3 end',
            ['smith', 'smith%'],
        );
    }

    /**
     * Test that an equality column is bound the raw term while every pattern
     * carries it escaped.
     *
     * @return void
     */
    public function testEqualityBindsTheRawTermAndPatternsBindItEscaped(): void
    {
        $query = $this->rank(['name' => SearchStrategy::EXACT, 'email' => SearchStrategy::SUBSTRING], 'like', '50%');

        self::assertSame(['50%', '50!%', '50!%%', '% 50!%%'], $query->getQuery()->getRawBindings()['order']);
    }

    /**
     * Test that the ranking is appended after an order already on the query.
     *
     * @return void
     */
    public function testAppendsAfterAnOrderAlreadyOnTheQuery(): void
    {
        $query = User::query();

        $query->getQuery()->orderBy('status');

        (new RelevanceOrder)->apply($query, $this->plan(['name' => SearchStrategy::SUBSTRING]), SearchTerm::from(self::TERM), 'like');

        self::assertStringStartsWith('select * from "users" order by "status" asc, case when', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that a null second column does not demote a row its first column
     * ranks.
     *
     * @return void
     */
    public function testANullColumnDoesNotDemoteARow(): void
    {
        Device::create(['name' => 'Smithers', 'platform' => Platform::WEB]);
        Device::create(['name' => 'Smith', 'platform' => null]);

        $query = Device::query();

        (new RelevanceOrder)->apply(
            $query,
            $this->plan(['name' => SearchStrategy::SUBSTRING, 'platform' => SearchStrategy::SUBSTRING]),
            SearchTerm::from(self::TERM),
            'like',
        );

        self::assertSame(['Smith', 'Smithers'], $query->pluck('name')->all());
    }

    /**
     * Rank a user query by the term over the given searchable columns.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Enums\SearchStrategy>  $columns
     * @param  'ilike'|'like'  $operator
     * @param  string  $term
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function rank(array $columns, string $operator = 'like', string $term = self::TERM): Builder
    {
        $query = User::query();

        (new RelevanceOrder)->apply($query, $this->plan($columns), SearchTerm::from($term), $operator);

        return $query;
    }

    /**
     * Build a search plan declaring the given columns.
     *
     * @param  array<string, \SineMacula\ApiToolkit\Enums\SearchStrategy>  $columns
     * @return \SineMacula\ApiToolkit\Search\SearchPlan
     */
    private function plan(array $columns): SearchPlan
    {
        return SearchPlan::build(new CompiledSchema([], [], [], [], [], $columns));
    }

    /**
     * Assert that the query is ordered by the ranking alone, its bindings filed
     * under the order, one per placeholder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>  $query
     * @param  string  $sql
     * @param  array<int, string>  $bindings
     * @return void
     */
    private function assertRanked(Builder $query, string $sql, array $bindings): void
    {
        self::assertSame('select * from "users" order by ' . $sql . ' asc', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame($bindings, $query->getQuery()->getRawBindings()['order']);
        self::assertSame([], $query->getQuery()->getRawBindings()['where']);
        self::assertPlaceholderPerBinding($sql, $bindings);
    }
}
