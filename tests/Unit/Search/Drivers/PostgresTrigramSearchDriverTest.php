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
use Tests\Concerns\AssertsBoundPlaceholders;
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
    use AssertsBoundPlaceholders;

    /** @var string The connection name the predicates are compiled against */
    private const string CONNECTION = 'pgsql_grammar';

    /** @var string The term every test searches for */
    private const string TERM = 'smith';

    /** @var array<int, string> The statements the driver read the catalogue with */
    private array $statements = [];

    /** @var array<int, array<int, mixed>> The bindings the driver read the catalogue with */
    private array $bindings = [];

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
        $this->bindings   = [];

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

        self::assertSame('select * from "users" where "users"."name" = ?', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame([self::TERM], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
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

        self::assertSame('select * from "users" where "users"."name" ilike ? escape \'!\'', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame([self::TERM . '%'], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
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

        self::assertSame('select * from "users" where "users"."name" ilike ? escape \'!\'', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame(['%' . self::TERM . '%'], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
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
            'select * from "users" where "users"."name" ilike ? escape \'!\' or "users"."email" ilike ? escape \'!\'',
            $query->toSql(), // @phpstan-ignore staticMethod.dynamicCall
        );
        self::assertSame(['%' . self::TERM . '%', '%' . self::TERM . '%'], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that every value bound to the statement keeps a placeholder of its
     * own once the quoted literals are read out of it, since a literal left
     * open swallows the placeholder of the clause beside it and leaves the
     * value it stood for with nothing to bind to.
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
     * Test that an index built over a trigram operator class proves a pattern
     * match, and that it is found among the several a real table carries.
     *
     * @return void
     */
    public function testAcceptsATrigramIndexOverTheColumn(): void
    {
        $connection = $this->catalogue([
            'CREATE UNIQUE INDEX users_pkey ON public.users USING btree (id)',
            'CREATE INDEX users_name_trgm ON public.users USING gin (name gin_trgm_ops)',
        ], true);

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection));
    }

    /**
     * Test that the extension is asked for before the indexes it would have to
     * have created, that both are read from the connection's own catalogue, and
     * that each statement carries the value its placeholder stands for.
     *
     * @return void
     */
    public function testReadsTheExtensionThenTheIndexDefinitions(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_name_trgm ON public.users USING gin (name gin_trgm_ops)'], true);

        (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection);

        self::assertSame([
            'select 1 from pg_extension where extname = ?',
            'select pg_get_indexdef(i.indexrelid) as indexdef from pg_index i '
                . 'join pg_class c on c.oid = i.indrelid '
                . 'join pg_namespace n on n.oid = c.relnamespace '
                . 'where n.nspname = coalesce(?::text, current_schema()) and c.relname = ? '
                . 'and i.indisvalid and i.indpred is null',
        ], $this->statements);
        self::assertSame([['pg_trgm'], [null, 'users']], $this->bindings);
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

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $connection));
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

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name', 'email'], 'users', $connection));
    }

    /**
     * Test that each declared column is proved on its own, so the one an index
     * covers is accepted while the one beside it is reported.
     *
     * @return void
     */
    public function testProvesEachDeclaredColumnSeparately(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_name_trgm ON public.users USING gin (name gin_trgm_ops)'], true);

        self::assertSame(
            ['email' => ['Column "email" is declared searchable with the "substring" strategy, which needs a trigram index over that column on table "users"']],
            (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name', 'email'], 'users', $connection),
        );
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
            ['name' => ['Column "name" is declared searchable with the "substring" strategy, which needs a trigram index over that column on table "users"']],
            (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection),
        );
    }

    /**
     * Test that a column left without a trigram index is reported for each of
     * them rather than for the first alone.
     *
     * @return void
     */
    public function testReportsEveryColumnWithoutATrigramIndex(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_name_index ON public.users USING btree (name)'], true);

        $defects = (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name', 'email'], 'users', $connection);

        self::assertSame(['name', 'email'], array_keys($defects));
    }

    /**
     * Test that a missing extension is reported as itself rather than as a
     * missing index, since no index the operator creates can exist without it,
     * and that it is reported against every declared column.
     *
     * @return void
     */
    public function testReportsTheMissingExtensionRatherThanTheMissingIndex(): void
    {
        $connection = $this->catalogue([], false);

        $defect = 'The "substring" strategy is served by the pg_trgm extension, and that extension is not installed on this connection';

        self::assertSame(
            ['name' => [$defect], 'email' => [$defect]],
            (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name', 'email'], 'users', $connection),
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

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::EXACT, ['id'], 'users', $connection));
    }

    /**
     * Test that a catalogue row whose definition is not readable is stepped
     * past, so one unreadable entry does not hide the index beside it.
     *
     * @return void
     */
    public function testReadsPastACatalogueRowWithNoReadableDefinition(): void
    {
        $schema = self::createStub(SchemaBuilder::class);

        $schema->method('getIndexes')->willReturn([]);

        $connection = self::createStub(Connection::class);

        $connection->method('getSchemaBuilder')->willReturn($schema);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('select')->willReturnCallback(static function (string $query): array {

            if (str_contains($query, 'pg_extension')) {
                return [(object) ['installed' => 1]];
            }

            return [
                (object) ['indexdef' => null],
                (object) ['indexdef' => 'CREATE INDEX users_name_trgm ON public.users USING gin (name gin_trgm_ops)'],
            ];
        });

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection));
    }

    /**
     * Test that the catalogue is read under the name it actually holds, which
     * carries the connection's table prefix the model's own name does not.
     *
     * @return void
     */
    public function testReadsTheCatalogueUnderThePrefixedRelationName(): void
    {
        $connection = $this->catalogue([], true, [], 'app_');

        (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection);

        self::assertSame([null, 'app_users'], $this->bindings[1]);
    }

    /**
     * Test that a schema-qualified table is read under its own namespace rather
     * than the connection's current one, where the relation does not exist.
     *
     * @return void
     */
    public function testReadsASchemaQualifiedTableUnderItsOwnNamespace(): void
    {
        $connection = $this->catalogue([], true);

        (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'audit.users', $connection);

        self::assertSame(['audit', 'users'], $this->bindings[1]);
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
     * @param  string  $prefix
     * @return \Illuminate\Database\Connection
     */
    private function catalogue(array $definitions = [], bool $extension = true, array $indexes = [], string $prefix = ''): Connection
    {
        $schema = self::createStub(SchemaBuilder::class);

        $schema->method('getIndexes')->willReturn($indexes);

        $connection = self::createStub(Connection::class);

        $connection->method('getSchemaBuilder')->willReturn($schema);
        $connection->method('getTablePrefix')->willReturn($prefix);
        $connection->method('select')->willReturnCallback(function (string $query, array $bindings = []) use ($definitions, $extension): array {

            $this->statements[] = $query;
            $this->bindings[]   = $bindings;

            if (str_contains($query, 'pg_extension')) {
                return $extension ? [(object) ['installed' => 1]] : [];
            }

            return array_map(static fn (string $definition): object => (object) ['indexdef' => $definition], $definitions);
        });

        return $connection;
    }
}
