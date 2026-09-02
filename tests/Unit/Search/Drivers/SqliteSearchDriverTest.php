<?php

declare(strict_types = 1);

namespace Tests\Unit\Search\Drivers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\Drivers\SqliteSearchDriver;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the SqliteSearchDriver.
 *
 * The driver serves every strategy so a term behaves the same way locally as it
 * does in front of an engine that indexes it, and claims nothing about the
 * indexes behind them, which is what leaves the limitation visible rather than
 * hidden.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SqliteSearchDriver::class)]
final class SqliteSearchDriverTest extends TestCase
{
    /** @var string The term every test searches for */
    private const string TERM = 'smith';

    /**
     * Test that the driver implements every match strategy, so a development
     * connection answers the same declarations a production one does.
     *
     * @return void
     */
    public function testImplementsEveryMatchStrategy(): void
    {
        self::assertSame(SearchStrategy::cases(), (new SqliteSearchDriver)->supportedStrategies());
    }

    /**
     * Test that the driver claims no index proof for any strategy, so every
     * declaration it serves is refused unless the connection waives the proof.
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
     * Test that the driver reports no defect for any strategy, since a driver
     * that can prove nothing has found nothing rather than proved there is
     * nothing wrong.
     *
     * @return void
     */
    public function testReportsNoIndexDefectForAnyStrategy(): void
    {
        $driver = new SqliteSearchDriver;

        foreach (SearchStrategy::cases() as $strategy) {
            self::assertSame([], $driver->indexDefects($strategy, 'name', 'users', DB::connection()));
        }
    }

    /**
     * Test that an equality match is emitted as a plain comparison against the
     * qualified column.
     *
     * @return void
     */
    public function testEmitsAPlainComparisonForAnEqualityMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::EXACT);

        self::assertSame('select * from "users" where "users"."name" = ?', $query->toSql());
        self::assertSame([self::TERM], $query->getBindings());
    }

    /**
     * Test that a prefix match names the escape character the term was escaped
     * with, which SQLite reads as a literal without it.
     *
     * @return void
     */
    public function testEmitsATrailingWildcardComparisonForAPrefixMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::PREFIX);

        self::assertSame('select * from "users" where "users"."name" like ? escape \'\\\'', $query->toSql());
        self::assertSame([self::TERM . '%'], $query->getBindings());
    }

    /**
     * Test that an anywhere-match wraps the term in wildcards at both ends.
     *
     * @return void
     */
    public function testEmitsAWildcardComparisonForAnAnywhereMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::SUBSTRING);

        self::assertSame('select * from "users" where "users"."name" like ? escape \'\\\'', $query->toSql());
        self::assertSame(['%' . self::TERM . '%'], $query->getBindings());
    }

    /**
     * Test that every declared column contributes its own comparison, so a
     * match on any one of them is a match.
     *
     * @return void
     */
    public function testMatchesEachColumnSeparately(): void
    {
        $query = $this->apply(['name', 'email'], SearchStrategy::SUBSTRING);

        self::assertSame(
            'select * from "users" where "users"."name" like ? escape \'\\\' or "users"."email" like ? escape \'\\\'',
            $query->toSql(),
        );
        self::assertSame(['%' . self::TERM . '%', '%' . self::TERM . '%'], $query->getBindings());
    }

    /**
     * Test that a wildcard carried by the term matches itself rather than every
     * row, which is what the escape clause exists for.
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
