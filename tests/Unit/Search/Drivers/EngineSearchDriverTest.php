<?php

declare(strict_types = 1);

namespace Tests\Unit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility;
use SineMacula\ApiToolkit\Search\Drivers\EngineSearchDriver;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Search\StubEngineSearchDriver;
use Tests\Fixtures\Search\StubFilteringSearchDriver;
use Tests\TestCase;

/**
 * Tests for the EngineSearchDriver base, through a fixture driver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EngineSearchDriver::class)]
final class EngineSearchDriverTest extends TestCase
{
    /** @var string The term every test searches for */
    private const string TERM = 'smith';

    /**
     * Test that an index the engine would refuse to plan against is dropped.
     *
     * The catalogue reports such an index exactly as it reports a usable one.
     *
     * @return void
     */
    public function testDropsAnIndexTheEngineWouldRefuseToPlanAgainst(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree']]);

        self::assertSame([], (new StubFilteringSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));

        $driver = new StubFilteringSearchDriver(new IndexEligibility(['users_name_index']));

        self::assertNotSame([], $driver->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that an index is refused whatever case the engine names it in.
     *
     * The catalogue lowers the name while an engine may report it as declared.
     *
     * @return void
     */
    public function testRefusesAnIndexWhateverCaseTheEngineNamesItIn(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree']]);

        $driver = new StubFilteringSearchDriver(new IndexEligibility(['USERS_Name_Index']));

        self::assertNotSame([], $driver->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that a partial or expression index proves no match.
     *
     * The catalogue's column list cannot express either restriction.
     *
     * @return void
     */
    public function testDropsAnIndexWhoseReportedColumnsOverstateIt(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree']]);

        $restricted = new StubFilteringSearchDriver(new IndexEligibility([], ['users_name_index']));
        $expressed  = new StubFilteringSearchDriver(new IndexEligibility([], [], ['users_name_index']));

        self::assertNotSame([], $restricted->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
        self::assertNotSame([], $expressed->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));

        // Without either fact the same index proves the column
        self::assertSame([], (new StubFilteringSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that an index whose leading key lacks the column's order still
     * proves an equality match.
     *
     * A truncated or pattern-class key still finds rows by the column.
     *
     * @return void
     */
    public function testKeepsAnIndexWhoseLeadingKeyLacksTheColumnsOrder(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree']]);

        $driver = new StubFilteringSearchDriver(new IndexEligibility([], [], [], ['users_name_index']));

        self::assertSame([], $driver->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that an index whose leading key is collated apart is refused.
     *
     * A comparison on the column cannot use a key ordered by another collation.
     *
     * @return void
     */
    public function testRefusesAnIndexWhoseLeadingKeyIsCollatedApart(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree']]);

        $driver = new StubFilteringSearchDriver(new IndexEligibility([], [], [], [], ['users_name_index']));

        self::assertSame([
            'name' => ['Column "name" is declared searchable with the "exact" strategy, which needs an index leading with that column on table "users"'],
        ], $driver->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that a plain index proves the match beside one collated apart.
     *
     * @return void
     */
    public function testAPlainIndexProvesTheMatchBesideOneCollatedApart(): void
    {
        $connection = $this->catalogue([
            ['name' => 'users_name_c_index', 'columns' => ['name'], 'type' => 'btree'],
            ['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree'],
        ]);

        $driver = new StubFilteringSearchDriver(new IndexEligibility([], [], [], [], ['users_name_c_index']));

        self::assertSame([], $driver->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that a refused index does not stop a later one proving the match.
     *
     * @return void
     */
    public function testKeepsReadingPastAnIndexItRefuses(): void
    {
        $connection = $this->catalogue([
            ['name' => 'users_name_hidden', 'columns' => ['name'], 'type' => 'btree'],
            ['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree'],
        ]);

        $driver = new StubFilteringSearchDriver(new IndexEligibility(['users_name_hidden']));

        self::assertSame([], $driver->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));

        // Alone, the refused index leaves the column unproved
        $only = $this->catalogue([['name' => 'users_name_hidden', 'columns' => ['name'], 'type' => 'btree']]);

        self::assertNotSame([], $driver->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $only));
    }

    /**
     * Test that a driver over an engine implements every match strategy.
     *
     * @return void
     */
    public function testImplementsEveryMatchStrategy(): void
    {
        self::assertSame(SearchStrategy::cases(), (new StubEngineSearchDriver)->supportedStrategies());
    }

    /**
     * Test that a driver over an engine can prove index backing for every
     * strategy.
     *
     * @return void
     */
    public function testClaimsItCanProveIndexBackingForEveryStrategy(): void
    {
        $driver = new StubEngineSearchDriver;

        foreach (SearchStrategy::cases() as $strategy) {
            self::assertTrue($driver->canVerifyIndexBacking($strategy, $this->catalogue()));
        }
    }

    /**
     * Test that the base refuses no combination of strategies by default.
     *
     * @return void
     */
    public function testRefusesNoCombinationOfStrategies(): void
    {
        $driver = new StubEngineSearchDriver;

        self::assertNull($driver->combinationDefect(SearchStrategy::cases()));
        self::assertNull($driver->combinationDefect([SearchStrategy::SUBSTRING, SearchStrategy::PREFIX]));
        self::assertNull($driver->combinationDefect([]));
    }

    /**
     * Test that the equality match compares against the table-qualified column.
     *
     * @return void
     */
    public function testEmitsTheEqualityMatchAgainstTheQualifiedColumn(): void
    {
        $query = $this->apply(['name'], SearchStrategy::EXACT);

        self::assertSame('select * from "users" where "users"."name" = ?', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame([self::TERM], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that a prefix declaration reaches the prefix half.
     *
     * @return void
     */
    public function testDispatchesAPrefixDeclarationToThePrefixHalf(): void
    {
        $query = $this->apply(['name'], SearchStrategy::PREFIX);

        self::assertSame('select * from "users" where "users"."name" like ?', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame([self::TERM . '%'], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that an anywhere declaration reaches the anywhere half.
     *
     * @return void
     */
    public function testDispatchesAnAnywhereDeclarationToTheAnywhereHalf(): void
    {
        $query = $this->apply(['name'], SearchStrategy::SUBSTRING);

        self::assertSame('select * from "users" where "users"."name" glob ?', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame([self::TERM], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that every declared column contributes its own predicate, or'd.
     *
     * @return void
     */
    public function testAppliesOnePredicatePerColumn(): void
    {
        $query = $this->apply(['name', 'email'], SearchStrategy::EXACT);

        self::assertSame('select * from "users" where "users"."name" = ? or "users"."email" = ?', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame([self::TERM, self::TERM], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that a strategy declaring no column emits no predicate.
     *
     * @return void
     */
    public function testAStrategyDeclaringNoColumnEmitsNoPredicate(): void
    {
        $query = $this->apply([], SearchStrategy::SUBSTRING);

        self::assertSame('select * from "users"', $query->toSql()); // @phpstan-ignore staticMethod.dynamicCall
        self::assertSame([], $query->getBindings()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that the index proof is dispatched to the strategy's half.
     *
     * @return void
     */
    public function testDispatchesTheIndexProofToTheHalfTheStrategyBelongsTo(): void
    {
        $driver = new StubEngineSearchDriver;

        self::assertSame(
            ['name' => [StubEngineSearchDriver::PREFIX_DEFECT]],
            $driver->indexDefects(SearchStrategy::PREFIX, ['name'], 'users', $this->catalogue()),
        );
        self::assertSame(
            ['name' => [StubEngineSearchDriver::SUBSTRING_DEFECT]],
            $driver->indexDefects(SearchStrategy::SUBSTRING, ['name'], 'users', $this->catalogue()),
        );
    }

    /**
     * Test that an ordinary index leading with the column proves an equality
     * match, wherever it appears among the table's indexes.
     *
     * @return void
     */
    public function testAcceptsAnEqualityMatchLedByAnOrdinaryIndex(): void
    {
        $connection = $this->catalogue([
            ['name' => 'users_pkey', 'columns' => ['id'], 'type' => 'btree'],
            ['name' => 'users_email_unique', 'columns' => ['email'], 'type' => 'btree'],
            ['name' => 'users_name_index', 'columns' => ['name', 'status'], 'type' => 'BTREE'],
        ]);

        self::assertSame([], (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that each declared column is proved on its own.
     *
     * @return void
     */
    public function testProvesEachDeclaredColumnSeparately(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree']]);

        self::assertSame(
            ['email' => ['Column "email" is declared searchable with the "exact" strategy, which needs an index leading with that column on table "users"']],
            (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name', 'email'], 'users', $connection),
        );
    }

    /**
     * Test that every unindexed column is reported, not just the first.
     *
     * @return void
     */
    public function testReportsEveryUnindexedColumn(): void
    {
        $defects = (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name', 'email'], 'users', $this->catalogue());

        self::assertSame(['name', 'email'], array_keys($defects));
    }

    /**
     * Test that an unreadable catalogue entry is stepped over.
     *
     * Reaching into an object row for a name would be fatal, not a miss.
     *
     * @return void
     */
    public function testReadsPastAnUnreadableCatalogueEntry(): void
    {
        $connection = $this->catalogue([
            'users_name_index',
            (object) ['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree'],
            ['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree'],
        ]);

        self::assertSame([], (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that an index carrying the column anywhere but first does not prove
     * an equality match.
     *
     * @return void
     */
    public function testRefusesAnEqualityMatchOnATrailingIndexColumn(): void
    {
        $connection = $this->catalogue([['name' => 'users_status_name_index', 'columns' => ['status', 'name'], 'type' => 'btree']]);

        self::assertSame(
            ['name' => ['Column "name" is declared searchable with the "exact" strategy, which needs an index leading with that column on table "users"']],
            (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection),
        );
    }

    /**
     * Test that an index of another kind does not prove an equality match.
     *
     * @return void
     */
    public function testRefusesAnEqualityMatchLedByAnIndexOfAnotherKind(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_ngram', 'columns' => ['name'], 'type' => 'fulltext']]);

        self::assertCount(1, (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that a catalogue entry missing its name, columns or kind is skipped.
     *
     * @return void
     */
    public function testPassesOverACatalogueEntryMissingWhatIdentifiesIt(): void
    {
        $connection = $this->catalogue([
            'users_name_index',
            ['name' => null, 'columns' => ['name'], 'type' => 'btree'],
            ['name' => 'users_name_index', 'columns' => 'name', 'type' => 'btree'],
            ['name' => 'users_name_index', 'columns' => ['name'], 'type' => null],
        ]);

        self::assertCount(1, (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that an index carrying a column that is not a name is passed over.
     *
     * The bad column trails one the proof would accept on its own.
     *
     * @return void
     */
    public function testPassesOverAnIndexCarryingAColumnThatIsNotAName(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_index', 'columns' => ['name', 1], 'type' => 'btree']]);

        self::assertCount(1, (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name'], 'users', $connection));
    }

    /**
     * Test that the catalogue is read once for the whole declaration.
     *
     * @return void
     */
    public function testReadsTheCatalogueOnceForTheWholeDeclaration(): void
    {
        $schema = self::createMock(SchemaBuilder::class);

        $schema->expects(self::once())
            ->method('getIndexes')
            ->willReturn([['name' => 'users_name_index', 'columns' => ['name'], 'type' => 'btree']]);

        $connection = self::createStub(Connection::class);

        $connection->method('getSchemaBuilder')->willReturn($schema);

        $defects = (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, ['name', 'email', 'status'], 'users', $connection);

        self::assertSame(['email', 'status'], array_keys($defects));
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

        (new StubEngineSearchDriver)->apply($query, $columns, SearchTerm::from(self::TERM), $strategy);

        return $query;
    }

    /**
     * Build a connection reporting the given indexes.
     *
     * @param  array<int, mixed>  $indexes
     * @return \Illuminate\Database\Connection
     */
    private function catalogue(array $indexes = []): Connection
    {
        $schema = self::createStub(SchemaBuilder::class);

        $schema->method('getIndexes')->willReturn($indexes);

        $connection = self::createStub(Connection::class);

        $connection->method('getSchemaBuilder')->willReturn($schema);

        return $connection;
    }
}
