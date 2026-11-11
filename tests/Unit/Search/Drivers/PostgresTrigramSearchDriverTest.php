<?php

declare(strict_types = 1);

namespace Tests\Unit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
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
 * Tests for the PostgresTrigramSearchDriver against an unconnected grammar.
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

    /** @var array<int, string> The statements the driver sent to a read replica */
    private array $replicaReads = [];

    /**
     * Register a PostgreSQL connection to compile the predicates against.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->statements   = [];
        $this->bindings     = [];
        $this->replicaReads = [];

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
     * Test that the driver implements every match strategy.
     *
     * @return void
     */
    public function testImplementsEveryMatchStrategy(): void
    {
        self::assertSame(SearchStrategy::cases(), (new PostgresTrigramSearchDriver)->supportedStrategies());
    }

    /**
     * Test that the driver claims it can prove index backing.
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
     * Test that a prefix match is emitted as a case-insensitive comparison.
     *
     * This matches the rows an engine with a case-folding collation returns.
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
     * Test that an anywhere-match is a case-insensitive wildcard comparison.
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
     * Test that every declared column contributes its own comparison.
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
     * Test that an index over a trigram operator class proves a pattern match.
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
     * Test that the extension, index states and definitions are read in turn.
     *
     * @return void
     */
    public function testReadsTheExtensionThenTheIndexStatesThenTheDefinitions(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_name_trgm ON public.users USING gin (name gin_trgm_ops)'], true);

        (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection);

        self::assertSame([
            'select 1 from pg_extension where extname = ?',
            'select lower(ic.relname) as name, '
                . '(not i.indisvalid)::int as disregarded, '
                . '(i.indpred is not null)::int as restricted, '
                . '(i.indkey[0] = 0)::int as expressed, '
                . '(am.amname = \'btree\' and i.indkey[0] <> 0 and '
                . 'not exists (select 1 from pg_opclass d where d.opcdefault and d.opcmethod = oc.opcmethod '
                . 'and d.opcintype = oc.opcintype and d.opcfamily = oc.opcfamily))::int as unordered, '
                . '(i.indkey[0] <> 0 and i.indcollation[0] <> 0 and i.indcollation[0] <> a.attcollation)::int as recollated '
                . 'from pg_index i '
                . 'join pg_class c on c.oid = i.indrelid '
                . 'join pg_namespace n on n.oid = c.relnamespace '
                . 'join pg_class ic on ic.oid = i.indexrelid '
                . 'join pg_am am on am.oid = ic.relam '
                . 'left join pg_opclass oc on oc.oid = i.indclass[0] '
                . 'left join pg_attribute a on a.attrelid = i.indrelid and a.attnum = i.indkey[0] '
                . 'where n.nspname = coalesce(?::text, current_schema()) and c.relname = ?',
            'select lower(ic.relname) as name, pg_get_indexdef(i.indexrelid) as indexdef from pg_index i '
                . 'join pg_class c on c.oid = i.indrelid '
                . 'join pg_namespace n on n.oid = c.relnamespace '
                . 'join pg_class ic on ic.oid = i.indexrelid '
                . 'where n.nspname = coalesce(?::text, current_schema()) and c.relname = ?',
        ], $this->statements);
        self::assertSame([['pg_trgm'], [null, 'users'], [null, 'users']], $this->bindings);
    }

    /**
     * Test that every catalogue read goes to the primary.
     *
     * The reads must all describe the same server.
     *
     * @return void
     */
    public function testReadsTheCatalogueFromThePrimary(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_name_trgm ON public.users USING gin (name gin_trgm_ops)'], true);

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection));
        self::assertCount(3, $this->statements);
        self::assertSame([], $this->replicaReads);
    }

    /**
     * Test that a quoted column under the gist trigram class proves a match.
     *
     * @return void
     */
    public function testAcceptsAQuotedColumnUnderTheOtherTrigramOperatorClass(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_name_trgm ON public.users USING gist ("name" gist_trgm_ops)'], true);

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $connection));
    }

    /**
     * Test that a multi-column trigram index proves each of its columns.
     *
     * @return void
     */
    public function testAcceptsAColumnCarriedAlongsideAnotherInOneTrigramIndex(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_search_trgm ON public.users USING gin (name gin_trgm_ops, email gin_trgm_ops)'], true);

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name', 'email'], 'users', $connection));
    }

    /**
     * Test that a trigram key collated apart from its column proves no match.
     *
     * @return void
     */
    public function testRefusesATrigramKeyCollatedApartFromItsColumn(): void
    {
        $connection = $this->catalogue(['CREATE INDEX users_name_trgm ON public.users USING gin (name COLLATE "C" gin_trgm_ops)'], true);

        $driver = new PostgresTrigramSearchDriver;

        self::assertSame(
            ['name' => ['Column "name" is declared searchable with the "prefix" strategy, which needs a trigram index over that column on table "users"']],
            $driver->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $connection),
        );
        self::assertSame(
            ['name' => ['Column "name" is declared searchable with the "substring" strategy, which needs a trigram index over that column on table "users"']],
            $driver->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection),
        );
    }

    /**
     * Test that a later trigram key proves a match behind one collated apart.
     *
     * The collation fact describes only the leading key.
     *
     * @return void
     */
    public function testAcceptsALaterTrigramKeyBehindOneCollatedApart(): void
    {
        $connection = $this->catalogue(
            ['CREATE INDEX users_index_0 ON public.users USING gin (other COLLATE "C" gin_trgm_ops, name gin_trgm_ops)'],
            recollated: ['users_index_0'],
        );

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $connection));
    }

    /**
     * Test that each declared column is proved on its own.
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
     * Test that every column without a trigram index is reported.
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
     * Test that a missing extension is reported in place of a missing index.
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
     * match.
     *
     * @return void
     */
    public function testAcceptsAnOrdinaryIndexLeadingWithTheColumnForAnEqualityMatch(): void
    {
        $connection = $this->catalogue([], true, [['name' => 'users_pkey', 'columns' => ['id'], 'type' => 'btree']]);

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::EXACT, ['id'], 'users', $connection));
    }

    /**
     * Test that a catalogue row with no readable definition is stepped past.
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
        $connection->method('selectFromWriteConnection')->willReturnCallback(static function (string $query): array {

            if (str_contains($query, 'pg_extension')) {
                return [(object) ['installed' => 1]];
            }

            if (!str_contains($query, 'pg_get_indexdef')) {
                return [];
            }

            return [
                (object) ['name' => 'users_name_broken', 'indexdef' => null],
                (object) [
                    'name'     => 'users_name_trgm',
                    'indexdef' => 'CREATE INDEX users_name_trgm ON public.users USING gin (name gin_trgm_ops)',
                ],
            ];
        });

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $connection));
    }

    /**
     * Test that the catalogue is read under the prefixed relation name.
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
     * Test that a schema-qualified table is read under its own namespace.
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
     * Test that the index-state read asks for every disqualifying state.
     *
     * @return void
     */
    public function testReadsTheIndexesThePlannerWillNotUse(): void
    {
        $connection = $this->catalogue(indexes: [['name' => 'users_email_index', 'columns' => ['email'], 'type' => 'btree']]);

        (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::EXACT, ['email'], 'users', $connection);

        self::assertSame([
            'select lower(ic.relname) as name, '
                . '(not i.indisvalid)::int as disregarded, '
                . '(i.indpred is not null)::int as restricted, '
                . '(i.indkey[0] = 0)::int as expressed, '
                . '(am.amname = \'btree\' and i.indkey[0] <> 0 and '
                . 'not exists (select 1 from pg_opclass d where d.opcdefault and d.opcmethod = oc.opcmethod '
                . 'and d.opcintype = oc.opcintype and d.opcfamily = oc.opcfamily))::int as unordered, '
                . '(i.indkey[0] <> 0 and i.indcollation[0] <> 0 and i.indcollation[0] <> a.attcollation)::int as recollated '
                . 'from pg_index i '
                . 'join pg_class c on c.oid = i.indrelid '
                . 'join pg_namespace n on n.oid = c.relnamespace '
                . 'join pg_class ic on ic.oid = i.indexrelid '
                . 'join pg_am am on am.oid = ic.relam '
                . 'left join pg_opclass oc on oc.oid = i.indclass[0] '
                . 'left join pg_attribute a on a.attrelid = i.indrelid and a.attnum = i.indkey[0] '
                . 'where n.nspname = coalesce(?::text, current_schema()) and c.relname = ?',
        ], $this->statements);
        self::assertSame([[null, 'users']], $this->bindings);
    }

    /**
     * Test that an index the planner will not use proves no exact match.
     *
     * The shared catalogue reports an invalid index exactly as a usable one.
     *
     * @return void
     */
    public function testRefusesAnExactMatchBackedOnlyByAnIndexThePlannerWillNotUse(): void
    {
        $indexes = [['name' => 'users_email_index', 'columns' => ['email'], 'type' => 'btree']];

        $usable = $this->catalogue(indexes: $indexes);

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::EXACT, ['email'], 'users', $usable));

        $refused = $this->catalogue(indexes: $indexes, unusable: ['users_email_index']);

        self::assertNotSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::EXACT, ['email'], 'users', $refused));
    }

    /**
     * Test that an engine which cannot report index states leaves the proof as
     * it was.
     *
     * A refused read must not take every search down with it.
     *
     * @return void
     */
    public function testAnEngineThatCannotAnswerLeavesTheProofUnchanged(): void
    {
        $connection = $this->refusingCatalogue([['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree']]);

        self::assertSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that a trigram index the engine disregards proves no anywhere match.
     *
     * The definition alone looks usable, so only the index state can refuse it.
     *
     * @return void
     */
    public function testRefusesAnAnywhereMatchBackedOnlyByADisregardedTrigramIndex(): void
    {
        $definition = 'CREATE INDEX users_index_0 ON public.users USING gin (name gin_trgm_ops)';

        self::assertSame(
            [],
            (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $this->catalogue([$definition])),
        );

        $refused = $this->catalogue([$definition], unusable: ['users_index_0']);

        self::assertNotSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $refused));
    }

    /**
     * Test that a partial trigram index proves no anywhere match.
     *
     * @return void
     */
    public function testRefusesAnAnywhereMatchBackedOnlyByARestrictedTrigramIndex(): void
    {
        $definition = 'CREATE INDEX users_index_0 ON public.users USING gin (name gin_trgm_ops)';

        $refused = $this->catalogue([$definition], restricted: ['users_index_0']);

        self::assertNotSame([], (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $refused));
    }

    /**
     * Test that an index keyed on an expression still proves an anywhere match.
     *
     * This proof reads the index definition, which names the expression itself.
     *
     * @return void
     */
    public function testAcceptsAnAnywhereMatchBackedByAnExpressionKeyedTrigramIndex(): void
    {
        $definition = 'CREATE INDEX users_index_0 ON public.users USING gin (name gin_trgm_ops)';

        $catalogue = $this->catalogue([$definition], expressed: ['users_index_0']);

        self::assertSame(
            [],
            (new PostgresTrigramSearchDriver)->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $catalogue),
        );
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
     * Build a connection reporting the given catalogue state.
     *
     * @param  array<int, string>  $definitions
     * @param  bool  $extension
     * @param  array<int, array<string, mixed>>  $indexes
     * @param  string  $prefix
     * @param  array<int, string>  $unusable
     * @param  array<int, string>  $restricted
     * @param  array<int, string>  $expressed
     * @param  array<int, string>  $recollated
     * @return \Illuminate\Database\Connection
     */
    private function catalogue(
        array $definitions = [],
        bool $extension = true,
        array $indexes = [],
        string $prefix = '',
        array $unusable = [],
        array $restricted = [],
        array $expressed = [],
        array $recollated = [],
    ): Connection {
        $schema = self::createStub(SchemaBuilder::class);

        $schema->method('getIndexes')->willReturn($indexes);

        $connection = self::createStub(Connection::class);

        $connection->method('getSchemaBuilder')->willReturn($schema);
        $connection->method('getTablePrefix')->willReturn($prefix);
        $connection->method('getDriverName')->willReturn('pgsql');
        $connection->method('select')->willReturnCallback(function (string $query): array {

            $this->replicaReads[] = $query;

            return [];
        });
        $states = [];

        foreach (['disregarded' => $unusable, 'restricted' => $restricted, 'expressed' => $expressed, 'recollated' => $recollated] as $fact => $names) {
            foreach ($names as $name) {
                $states[] = (object) ['name' => $name, $fact => 1];
            }
        }

        $connection->method('selectFromWriteConnection')->willReturnCallback(function (string $query, array $bindings = []) use ($definitions, $extension, $states): array {

            $this->statements[] = $query;
            $this->bindings[]   = $bindings;

            if (str_contains($query, 'pg_extension')) {
                return $extension ? [(object) ['installed' => 1]] : [];
            }

            if (!str_contains($query, 'pg_get_indexdef')) {
                return $states;
            }

            return array_map(
                static fn (string $definition, int $position): object => (object) [
                    'name'     => 'users_index_' . $position,
                    'indexdef' => $definition,
                ],
                $definitions,
                array_keys($definitions),
            );
        });

        return $connection;
    }

    /**
     * Build a connection whose read of the index states fails.
     *
     * @param  array<int, array<string, mixed>>  $indexes
     * @return \Illuminate\Database\Connection
     */
    private function refusingCatalogue(array $indexes = []): Connection
    {
        $schema = self::createStub(SchemaBuilder::class);

        $schema->method('getIndexes')->willReturn($indexes);

        $connection = self::createStub(Connection::class);

        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('getSchemaBuilder')->willReturn($schema);
        $connection->method('selectFromWriteConnection')->willThrowException(
            new QueryException('pgsql', 'select', [], new \RuntimeException('permission denied')),
        );

        return $connection;
    }
}
