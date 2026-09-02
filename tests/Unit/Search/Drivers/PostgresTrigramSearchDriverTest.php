<?php

declare(strict_types = 1);

namespace Tests\Unit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\Drivers\PostgresTrigramSearchDriver;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the PostgresTrigramSearchDriver.
 *
 * The predicates are compiled against a PostgreSQL grammar the suite never
 * connects to, so the emitted clause and its bindings are asserted here
 * whatever engine the suite is running against; that the engine answers them
 * from a trigram index is proven by the driver-gated integration suite. The
 * index proof is driven from a stubbed catalogue, so the missing extension and
 * the missing index are both exercised without either being removed first.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PostgresTrigramSearchDriver::class)]
final class PostgresTrigramSearchDriverTest extends TestCase
{
    /** @var string The connection name the predicates are compiled against */
    private const string CONNECTION = 'pgsql_grammar';

    /** @var string The term every test searches for */
    private const string TERM = 'smith';

    /** @var array<int, string> The statements the driver read the catalogue with */
    private array $statements = [];

    /**
     * Register a PostgreSQL connection the driver compiles its predicates
     * against.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->statements = [];

        Config::set('database.connections.' . self::CONNECTION, [
            'driver'   => 'pgsql',
            'host'     => '127.0.0.1',
            'database' => 'unused',
            'username' => 'unused',
            'password' => '',
            'prefix'   => '',
            'charset'  => 'utf8',
        ]);
    }

    /**
     * Test that the driver implements every match strategy, so no declaration
     * is refused for a shape PostgreSQL can serve.
     *
     * @return void
     */
    public function testImplementsEveryMatchStrategy(): void
    {
        self::assertSame(SearchStrategy::cases(), (new PostgresTrigramSearchDriver)->supportedStrategies());
    }

    /**
     * Test that the driver claims it can prove index backing, so a declaration
     * it serves is checked against the live schema.
     *
     * @return void
     */
    public function testClaimsItCanProveIndexBacking(): void
    {
        $driver = new PostgresTrigramSearchDriver;

        foreach (SearchStrategy::cases() as $strategy) {
            self::assertTrue($driver->canVerifyIndexBacking($strategy, $this->catalogue()));
        }
    }

    /**
     * Test that an equality match is emitted as a plain comparison against the
     * qualified column, which an ordinary index answers.
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
     * Test that a prefix match is emitted as a case-insensitive comparison, so
     * the same declaration answers the same rows here as on an engine whose
     * collation folds case.
     *
     * @return void
     */
    public function testEmitsACaseInsensitiveComparisonForAPrefixMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::PREFIX);

        self::assertSame('select * from "users" where "users"."name" ilike ? escape \'\\\'', $query->toSql());
        self::assertSame([self::TERM . '%'], $query->getBindings());
    }

    /**
     * Test that an anywhere-match is emitted as a case-insensitive comparison
     * wrapped in wildcards, which a trigram index serves from either end.
     *
     * @return void
     */
    public function testEmitsAWildcardComparisonForAnAnywhereMatch(): void
    {
        $query = $this->apply(['name'], SearchStrategy::SUBSTRING);

        self::assertSame('select * from "users" where "users"."name" ilike ? escape \'\\\'', $query->toSql());
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
            'select * from "users" where "users"."name" ilike ? escape \'\\\' or "users"."email" ilike ? escape \'\\\'',
            $query->toSql(),
        );
        self::assertSame(['%' . self::TERM . '%', '%' . self::TERM . '%'], $query->getBindings());
    }

    /**
     * Test that an index built over a trigram operator class proves a pattern
     * match.
     *
     * @return void
     */
    public function testAcceptsATrigramIndexOverTheColumn(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_name_trgm ON public.users USING gin (name gin_trgm_ops)'], true);

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, 'name', 'users', $connection));
    }

    /**
     * Test that the extension is asked for before the indexes it would have to
     * have created, and that both are read from the connection's own catalogue.
     *
     * @return void
     */
    public function testReadsTheExtensionThenTheIndexDefinitions(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_name_trgm ON public.users USING gin (name gin_trgm_ops)'], true);

        (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, 'name', 'users', $connection);

        self::assertSame([
            'select 1 from pg_extension where extname = ?',
            'select indexdef from pg_indexes where schemaname = current_schema() and tablename = ?',
        ], $this->statements);
    }

    /**
     * Test that the other trigram operator class proves a pattern match too,
     * and that a quoted column name in the definition is read.
     *
     * @return void
     */
    public function testAcceptsAQuotedColumnUnderTheOtherTrigramOperatorClass(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_name_trgm ON public.users USING gist ("name" gist_trgm_ops)'], true);

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::PREFIX, 'name', 'users', $connection));
    }

    /**
     * Test that a column carried by a trigram index alongside another is read,
     * so a multi-column index proves each of its columns.
     *
     * @return void
     */
    public function testAcceptsAColumnCarriedAlongsideAnotherInOneTrigramIndex(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_search_trgm ON public.users USING gin (name gin_trgm_ops, email gin_trgm_ops)'], true);

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, 'email', 'users', $connection));
    }

    /**
     * Test that an index over the column under any other operator class does
     * not prove a pattern match.
     *
     * @return void
     */
    public function testRefusesAPatternMatchBackedOnlyByAnOrdinaryIndex(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_name_index ON public.users USING btree (name)'], true);

        self::assertSame(
            ['Column "name" is declared searchable with the "substring" strategy, which needs a trigram index over that column on table "users"'],
            (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, 'name', 'users', $connection),
        );
    }

    /**
     * Test that a missing extension is reported as itself rather than as a
     * missing index, since no index the operator creates can exist without it.
     *
     * @return void
     */
    public function testReportsTheMissingExtensionRatherThanTheMissingIndex(): void
    {
        $connection = $this->catalogue([], false);

        self::assertSame(
            ['Column "name" is declared searchable with the "substring" strategy, which is served by the pg_trgm extension, '
                . 'and that extension is not installed on this connection'],
            (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, 'name', 'users', $connection),
        );
    }

    /**
     * Test that an ordinary index leading with the column proves an equality
     * match, which reads the column as it is stored.
     *
     * @return void
     */
    public function testAcceptsAnOrdinaryIndexLeadingWithTheColumnForAnEqualityMatch(): void
    {
        $connection = $this->catalogue([], true, [['name' => 'users_pkey', 'columns' => ['id'], 'type' => 'btree']]);

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::EXACT, 'id', 'users', $connection));
    }

    /**
     * Apply the term to a fresh query compiled against the PostgreSQL grammar.
     *
     * @param  array<int, string>  $columns
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function apply(array $columns, SearchStrategy $strategy): Builder
    {
        $query = User::on(self::CONNECTION);

        (new PostgresTrigramSearchDriver)->apply($query, $columns, SearchTerm::from(self::TERM), $strategy);

        return $query;
    }

    /**
     * Build a connection reporting the given index definitions, extension
     * state, and indexes.
     *
     * @param  array<int, string>  $definitions
     * @param  bool  $extension
     * @param  array<int, array<string, mixed>>  $indexes
     * @return \Illuminate\Database\Connection
     */
    private function catalogue(array $definitions = [], bool $extension = true, array $indexes = []): Connection
    {
        $schema = self::createStub(SchemaBuilder::class);

        $schema->method('getIndexes')->willReturn($indexes);

        $connection = self::createStub(Connection::class);

        $connection->method('getSchemaBuilder')->willReturn($schema);
        $connection->method('select')->willReturnCallback(function (string $query) use ($definitions, $extension): array {

            $this->statements[] = $query;

            if (str_contains($query, 'pg_extension')) {
                return $extension ? [(object) ['installed' => 1]] : [];
            }

            return array_map(static fn (string $definition): object => (object) ['indexdef' => $definition], $definitions);
        });

        return $connection;
    }
}
