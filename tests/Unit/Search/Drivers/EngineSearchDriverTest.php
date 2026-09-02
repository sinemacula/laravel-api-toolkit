<?php

declare(strict_types = 1);

namespace Tests\Unit\Search\Drivers;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\Drivers\EngineSearchDriver;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Search\StubEngineSearchDriver;
use Tests\TestCase;

/**
 * Tests for the EngineSearchDriver base.
 *
 * Exercised through a fixture driver whose two engine-specific halves name
 * themselves, so the dispatch, the equality match, and the catalogue read the
 * base owns are asserted without going through the grammar or the index kinds
 * of any one engine.
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
     * Test that a driver over an engine implements every match strategy.
     *
     * @return void
     */
    public function testImplementsEveryMatchStrategy(): void
    {
        self::assertSame(SearchStrategy::cases(), (new StubEngineSearchDriver)->supportedStrategies());
    }

    /**
     * Test that a driver over an engine claims it can prove index backing for
     * every strategy, since the engine's own catalogue can be read back.
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
     * Test that the equality match the base owns is emitted as a comparison
     * against the column qualified with its table, so the clause stays
     * unambiguous under a join.
     *
     * @return void
     */
    public function testEmitsTheEqualityMatchAgainstTheQualifiedColumn(): void
    {
        $query = $this->apply(['name'], SearchStrategy::EXACT);

        self::assertSame('select * from "users" where "users"."name" = ?', $query->toSql());
        self::assertSame([self::TERM], $query->getBindings());
    }

    /**
     * Test that a prefix declaration reaches the prefix half rather than any
     * other.
     *
     * @return void
     */
    public function testDispatchesAPrefixDeclarationToThePrefixHalf(): void
    {
        $query = $this->apply(['name'], SearchStrategy::PREFIX);

        self::assertSame('select * from "users" where "users"."name" like ?', $query->toSql());
        self::assertSame([self::TERM . '%'], $query->getBindings());
    }

    /**
     * Test that an anywhere declaration reaches the anywhere half rather than
     * any other.
     *
     * @return void
     */
    public function testDispatchesAnAnywhereDeclarationToTheAnywhereHalf(): void
    {
        $query = $this->apply(['name'], SearchStrategy::SUBSTRING);

        self::assertSame('select * from "users" where instr("users"."name", ?) > 0', $query->toSql());
        self::assertSame([self::TERM], $query->getBindings());
    }

    /**
     * Test that every declared column contributes its own predicate, combined
     * by disjunction.
     *
     * @return void
     */
    public function testAppliesOnePredicatePerColumn(): void
    {
        $query = $this->apply(['name', 'email'], SearchStrategy::EXACT);

        self::assertSame('select * from "users" where "users"."name" = ? or "users"."email" = ?', $query->toSql());
        self::assertSame([self::TERM, self::TERM], $query->getBindings());
    }

    /**
     * Test that the index proof is dispatched to the half the strategy belongs
     * to.
     *
     * @return void
     */
    public function testDispatchesTheIndexProofToTheHalfTheStrategyBelongsTo(): void
    {
        $driver = new StubEngineSearchDriver;

        self::assertSame([StubEngineSearchDriver::PREFIX_DEFECT], $driver->indexDefects(SearchStrategy::PREFIX, 'name', 'users', $this->catalogue()));
        self::assertSame([StubEngineSearchDriver::SUBSTRING_DEFECT], $driver->indexDefects(SearchStrategy::SUBSTRING, 'name', 'users', $this->catalogue()));
    }

    /**
     * Test that an ordinary index leading with the column proves an equality
     * match.
     *
     * @return void
     */
    public function testAcceptsAnEqualityMatchLedByAnOrdinaryIndex(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_index', 'columns' => ['name', 'status'], 'type' => 'BTREE']]);

        self::assertSame([], (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, 'name', 'users', $connection));
    }

    /**
     * Test that an index carrying the column anywhere but first does not prove
     * an equality match, since only the leading key is read.
     *
     * @return void
     */
    public function testRefusesAnEqualityMatchOnATrailingIndexColumn(): void
    {
        $connection = $this->catalogue([['name' => 'users_status_name_index', 'columns' => ['status', 'name'], 'type' => 'btree']]);

        self::assertSame(
            ['Column "name" is declared searchable with the "exact" strategy, which needs an index leading with that column on table "users"'],
            (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, 'name', 'users', $connection),
        );
    }

    /**
     * Test that an index of another kind does not prove an equality match,
     * whatever it leads with.
     *
     * @return void
     */
    public function testRefusesAnEqualityMatchLedByAnIndexOfAnotherKind(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_ngram', 'columns' => ['name'], 'type' => 'fulltext']]);

        self::assertCount(1, (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, 'name', 'users', $connection));
    }

    /**
     * Test that a catalogue entry the connection reports without a name, a
     * column list, or a kind is passed over rather than read as a proof.
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

        self::assertCount(1, (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, 'name', 'users', $connection));
    }

    /**
     * Test that an index carrying a column the connection reports as something
     * other than a name is passed over whole, rather than being read as though
     * the columns after it had moved up.
     *
     * @return void
     */
    public function testPassesOverAnIndexCarryingAColumnThatIsNotAName(): void
    {
        $connection = $this->catalogue([['name' => 'users_name_index', 'columns' => [1, 'name'], 'type' => 'btree']]);

        self::assertCount(1, (new StubEngineSearchDriver)->indexDefects(SearchStrategy::EXACT, 'name', 'users', $connection));
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
