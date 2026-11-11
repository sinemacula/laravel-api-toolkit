<?php

declare(strict_types = 1);

namespace Tests\Unit\Search\Drivers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\Drivers\SqliteSearchDriver;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Concerns\AssertsBoundPlaceholders;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the SqliteSearchDriver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SqliteSearchDriver::class)]
final class SqliteSearchDriverTest extends TestCase
{
    use AssertsBoundPlaceholders;

    /** @var string The term every test searches for */
    private const string TERM = 'smith';

    /**
     * Test that the driver implements every match strategy.
     *
     * @return void
     */
    public function testImplementsEveryMatchStrategy(): void
    {
        self::assertSame(SearchStrategy::cases(), (new SqliteSearchDriver)->supportedStrategies());
    }

    /**
     * Test that the driver claims no index proof for any strategy.
     *
     * @return void
     */
    public function testClaimsNoIndexProofForAnyStrategy(): void
    {
        $driver = new SqliteSearchDriver;

        foreach (SearchStrategy::cases() as $strategy) {
            self::assertFalse($driver->canVerifyIndexBacking($strategy, DB::connection()));
        }
    }

    /**
     * Test that the driver reports no index defect for any strategy.
     *
     * @return void
     */
    public function testReportsNoIndexDefectForAnyStrategy(): void
    {
        $driver = new SqliteSearchDriver;

        foreach (SearchStrategy::cases() as $strategy) {
            self::assertSame([], $driver->indexDefects($strategy, ['name'], 'users', DB::connection()));
        }
    }

    /**
     * Test that the driver refuses no combination of strategies.
     *
     * @return void
     */
    public function testRefusesNoCombinationOfStrategies(): void
    {
        self::assertNull((new SqliteSearchDriver)->combinationDefect(SearchStrategy::cases()));
    }

    /**
     * Test that an equality match is a plain comparison on the column.
     *
     * @return void
     */
    public function testEmitsAPlainComparisonForAnEqualityMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::EXACT);

        self::assertSame('select * from "users" where "users"."name" = ?', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame([self::TERM], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that a prefix match names the escape character.
     *
     * SQLite reads the escape character as a literal without the clause.
     *
     * @return void
     */
    public function testEmitsATrailingWildcardComparisonForAPrefixMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::PREFIX);

        self::assertSame('select * from "users" where "users"."name" like ? escape \'!\'', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame([self::TERM . '%'], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that an anywhere-match wraps the term in wildcards at both ends.
     *
     * @return void
     */
    public function testEmitsAWildcardComparisonForAnAnywhereMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::SUBSTRING);

        self::assertSame('select * from "users" where "users"."name" like ? escape \'!\'', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame(['%' . self::TERM . '%'], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that every declared column contributes its own comparison.
     *
     * @return void
     */
    public function testMatchesEachColumnSeparately(): void
    {
        $query = $this->apply(['name', 'email'], SearchStrategy::SUBSTRING);

        self::assertSame(
            'select * from "users" where "users"."name" like ? escape \'!\' or "users"."email" like ? escape \'!\'',
            $query->toSql(), // @phpstan-ignore staticMethod.dynamicCall
        );
        self::assertSame(['%' . self::TERM . '%', '%' . self::TERM . '%'], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that every declared column contributes its own equality comparison.
     *
     * @return void
     */
    public function testMatchesEachColumnSeparatelyForAnEqualityMatch(): void
    {
        $query = $this->apply(['name', 'email'], SearchStrategy::EXACT);

        self::assertSame('select * from "users" where "users"."name" = ? or "users"."email" = ?', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame([self::TERM, self::TERM], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that every binding keeps a placeholder of its own.
     *
     * A quoted literal left open would swallow the placeholder beside it.
     *
     * @return void
     */
    public function testEveryBindingKeepsAPlaceholderOfItsOwn(): void
    {
        foreach (SearchStrategy::cases() as $strategy) {

            $query = $this->apply(['name', 'email'], $strategy);

            $sql      = $query->toSql(); // @phpstan-ignore staticMethod.dynamicCall
            $bindings = $query->getBindings(); // @phpstan-ignore staticMethod.dynamicCall

            self::assertPlaceholderPerBinding($sql, $bindings);
        }
    }

    /**
     * Test that a wildcard carried by the term matches itself.
     *
     * @return void
     */
    public function testAWildcardInTheTermMatchesItself(): void
    {
        User::create(['name' => 'Smith%Jones', 'email' => 'wild@example.com', 'status' => 'active']);
        User::create(['name' => 'Smithers', 'email' => 'plain@example.com', 'status' => 'active']);

        $query = User::query();

        (new SqliteSearchDriver)->apply($query, ['name'], SearchTerm::from('h%J'), SearchStrategy::SUBSTRING);

        self::assertSame(['Smith%Jones'], $query->pluck('name')->all());
    }

    /**
     * Apply the term to a fresh query against the development connection.
     *
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function apply(array $columns, SearchStrategy $strategy): Builder
    {
        $query = User::query();

        (new SqliteSearchDriver)->apply($query, $columns, SearchTerm::from(self::TERM), $strategy);

        return $query;
    }
}
