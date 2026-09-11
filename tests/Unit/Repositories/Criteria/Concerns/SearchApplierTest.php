<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException;
use SineMacula\ApiToolkit\Exceptions\UnservableSearchException;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\Fixtures\Resources\SearchableUserResource;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Tests for the SearchApplier.
 *
 * The connection the suite runs against cannot prove an index backs anything,
 * so each test states the waiver it needs rather than inheriting the shipped
 * list, and the refusals are driven from both sides: a driver that cannot
 * prove, and a connection that does not waive the proof.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SearchApplier::class)]
final class SearchApplierTest extends TestCase
{
    /** @var string The term every test searches for */
    private const string TERM = 'smith';

    /** @var string The connection the waiver names, of a pair sharing an engine */
    private const string WAIVED_CONNECTION = 'waived_sibling';

    /** @var string The connection the waiver leaves off, sharing that engine */
    private const string UNWAIVED_CONNECTION = 'unwaived_sibling';

    /** @var string The connection resolved to report no name of its own */
    private const string NAMELESS_CONNECTION = 'nameless';

    /** @var \SineMacula\ApiToolkit\Search\SearchDriverRegistry */
    private SearchDriverRegistry $drivers;

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier */
    private SearchApplier $applier;

    /**
     * Set up the applier under test with an empty driver registry.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->drivers = new SearchDriverRegistry;
        $this->applier = new SearchApplier($this->drivers);

        Config::set('api-toolkit.search.unverified_connections', [$this->connectionName()]);
    }

    /**
     * Test that a request carrying no term leaves the query untouched.
     *
     * @return void
     */
    public function testAbsentTermLeavesTheQueryUntouched(): void
    {
        $query = User::query();

        $this->applier->apply($query, null, SearchableFilterableUserResource::class);

        self::assertSame([], $query->getQuery()->wheres);
    }

    /**
     * Test that the whole search is applied as one nested group, so no filter
     * disjunction applied alongside it can escape the narrowing.
     *
     * @return void
     */
    public function testAppliesTheSearchAsASingleNestedGroup(): void
    {
        $query = $this->applySearch();

        $wheres = $query->getQuery()->wheres;

        self::assertCount(1, $wheres);
        self::assertSame('Nested', $wheres[0]['type']);
        self::assertSame('and', $wheres[0]['boolean']);
    }

    /**
     * Test that each declared strategy contributes its own group, combined with
     * the others by disjunction so a match on any one of them is a match.
     *
     * @return void
     */
    public function testCombinesEveryDeclaredStrategyWithDisjunction(): void
    {
        $query = $this->applySearch(SearchableUserResource::class);

        /** @var \Illuminate\Database\Query\Builder $group */
        $group = $query->getQuery()->wheres[0]['query'];

        self::assertCount(2, $group->wheres);
        self::assertSame('Nested', $group->wheres[0]['type']);
        self::assertSame('or', $group->wheres[1]['boolean']);
    }

    /**
     * Test that every declared column is bound through the pattern its own
     * strategy renders, rather than one shape being applied to all of them.
     *
     * @return void
     */
    public function testBindsThePatternDeclaredForEachColumn(): void
    {
        $query = $this->applySearch(SearchableUserResource::class);

        self::assertSame(
            [self::TERM, '%' . self::TERM . '%', '%' . self::TERM . '%'],
            $query->getQuery()->getBindings(),
        );
    }

    /**
     * Test that a resource declaring nothing searchable refuses the parameter
     * rather than answering it with the unnarrowed table.
     *
     * @return void
     */
    public function testResourceWithNoSearchableColumnIsRefused(): void
    {
        $this->registerDriver();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The search parameter is not permitted for this resource.');

        $this->applier->apply(User::query(), $this->term(), FilterableUserResource::class);
    }

    /**
     * Test that a model with no mapped resource refuses the parameter.
     *
     * @return void
     */
    public function testModelWithNoMappedResourceIsRefused(): void
    {
        $this->registerDriver();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The search parameter is not permitted for this resource.');

        $this->applier->apply(User::query(), $this->term(), null);
    }

    /**
     * Test that a resolved class that is not an API resource refuses the
     * parameter rather than being compiled as a schema.
     *
     * @return void
     */
    public function testNonApiResourceClassIsRefused(): void
    {
        $this->registerDriver();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The search parameter is not permitted for this resource.');

        $this->applier->apply(User::query(), $this->term(), \stdClass::class);
    }

    /**
     * Test that a connection with no registered driver fails rather than
     * dropping the narrowing predicate.
     *
     * @return void
     */
    public function testConnectionWithNoRegisteredDriverThrows(): void
    {
        $this->expectException(MissingSearchDriverException::class);
        $this->expectExceptionMessage(sprintf('No search driver is registered for the "%s" connection. Register one to serve a search on that connection.', $this->driver()));

        $this->applier->apply(User::query(), $this->term(), SearchableFilterableUserResource::class);
    }

    /**
     * Test that a driver asked for a strategy it does not implement throws
     * rather than emitting some other match shape.
     *
     * @return void
     */
    public function testStrategyTheDriverDoesNotImplementThrows(): void
    {
        $this->registerDriver(new PatternSearchDriver([SearchStrategy::SUBSTRING]));

        $this->expectException(UnservableSearchException::class);
        $this->expectExceptionMessage(sprintf(
            'The search driver serving the "%s" engine does not implement the "exact" match strategy this resource declares.',
            $this->driver(),
        ));

        $this->applier->apply(User::query(), $this->term(), SearchableUserResource::class);
    }

    /**
     * Test that a driver which cannot prove an index backs the strategy is
     * refused on a connection that does not waive the proof.
     *
     * @return void
     */
    public function testUnprovableIndexBackingThrowsWhenTheConnectionDoesNotWaiveIt(): void
    {
        Config::set('api-toolkit.search.unverified_connections', []);

        $this->registerDriver();

        $this->expectException(UnservableSearchException::class);
        $this->expectExceptionMessage(sprintf(
            'The search driver serving the "%s" connection cannot prove an index serves the "substring" match strategy, so the search would scan the table. '
            . 'List that connection under api-toolkit.search.unverified_connections to serve it regardless.',
            $this->connectionName(),
        ));

        $this->applier->apply(User::query(), $this->term(), SearchableFilterableUserResource::class);
    }

    /**
     * Test that a driver refusing the declared strategies together is refused
     * with the whole reason, before any predicate is emitted.
     *
     * @return void
     */
    public function testStrategiesTheDriverCannotServeTogetherThrows(): void
    {
        $this->registerDriver(new PatternSearchDriver(null, false, [], 'they cannot share a disjunction here'));

        $this->expectException(UnservableSearchException::class);
        $this->expectExceptionMessage(sprintf(
            'The search driver serving the "%s" engine cannot serve the match strategies this resource declares together, '
            . 'because they cannot share a disjunction here.',
            $this->driver(),
        ));

        $this->applier->apply(User::query(), $this->term(), SearchableUserResource::class);
    }

    /**
     * Test that a driver which proves index backing has its proof read on the
     * request path, so a missing index refuses the request rather than quietly
     * scanning where the build never ran the same check.
     *
     * @return void
     */
    public function testMissingIndexThrowsOnTheRequestPath(): void
    {
        Config::set('api-toolkit.search.unverified_connections', []);

        $this->registerDriver(new PatternSearchDriver(null, true, ['no trigram index over "name"']));

        $this->expectException(UnservableSearchException::class);
        $this->expectExceptionMessage(sprintf(
            'The "%s" connection carries no index serving the "substring" match strategy this resource declares, '
            . 'so the search would scan the table: no trigram index over "name".',
            $this->connectionName(),
        ));

        $this->applier->apply(User::query(), $this->term(), SearchableFilterableUserResource::class);
    }

    /**
     * Test that a waived connection stops at the waiver rather than going on to
     * read a proof the driver has already said it cannot give, which would turn
     * an empty answer into a refusal.
     *
     * @return void
     */
    public function testWaivedConnectionDoesNotGoOnToReadTheProof(): void
    {
        $this->registerDriver(new PatternSearchDriver(null, false, ['no index over "name"']));

        $query = User::query();

        $this->applier->apply($query, $this->term(), SearchableFilterableUserResource::class);

        self::assertCount(1, $query->getQuery()->wheres);
    }

    /**
     * Test that the waiver excuses only a driver that cannot prove anything:
     * one that can is held to its proof even where the connection is listed,
     * since the waiver exists for an absent proof rather than a failed one.
     *
     * @return void
     */
    public function testWaiverDoesNotExcuseADriverThatCanProve(): void
    {
        $this->registerDriver(new PatternSearchDriver(null, true, ['no trigram index over "name"']));

        $this->expectException(UnservableSearchException::class);

        $this->applier->apply(User::query(), $this->term(), SearchableFilterableUserResource::class);
    }

    /**
     * Test that a driver which can prove index backing needs no waiver.
     *
     * @return void
     */
    public function testDriverThatProvesIndexBackingNeedsNoWaiver(): void
    {
        Config::set('api-toolkit.search.unverified_connections', []);

        $this->registerDriver(new PatternSearchDriver(null, true));

        $query = User::query();

        $this->applier->apply($query, $this->term(), SearchableFilterableUserResource::class);

        self::assertCount(1, $query->getQuery()->wheres);
    }

    /**
     * Test that a waiver naming another connection does not waive this one.
     *
     * @return void
     */
    public function testWaiverForAnotherConnectionDoesNotWaiveThisOne(): void
    {
        Config::set('api-toolkit.search.unverified_connections', ['some-other-connection']);

        $this->registerDriver();

        $this->expectException(UnservableSearchException::class);

        $this->applier->apply(User::query(), $this->term(), SearchableFilterableUserResource::class);
    }

    /**
     * Test that the waiver names a connection rather than the engine behind it.
     *
     * Two connections speaking the same engine are waived apart: the one the
     * list names serves the search, and its sibling is still refused. Nothing
     * separates the pair but the name, so a waiver read as an engine cannot
     * tell them apart and would serve both.
     *
     * @return void
     */
    public function testWaiverNamesTheConnectionRatherThanTheEngineBehindIt(): void
    {
        $this->defineSiblingConnections();

        Config::set('api-toolkit.search.unverified_connections', [self::WAIVED_CONNECTION]);

        $this->registerDriver();

        $waived  = User::on(self::WAIVED_CONNECTION);
        $refused = User::on(self::UNWAIVED_CONNECTION);

        self::assertSame(
            $waived->getModel()->getConnection()->getDriverName(),
            $refused->getModel()->getConnection()->getDriverName(),
        );

        $this->applier->apply($waived, $this->term(), SearchableFilterableUserResource::class);

        self::assertCount(1, $waived->getQuery()->wheres);

        $this->expectException(UnservableSearchException::class);

        $this->applier->apply($refused, $this->term(), SearchableFilterableUserResource::class);
    }

    /**
     * Test that a connection reporting no name of its own is waived by nothing,
     * even where the list names the key it is configured under, and is refused
     * with the engine named because nothing else names it.
     *
     * @return void
     */
    public function testConnectionReportingNoNameOfItsOwnIsWaivedByNothing(): void
    {
        $this->defineNamelessConnection();

        Config::set('api-toolkit.search.unverified_connections', [self::NAMELESS_CONNECTION]);

        $this->drivers->register('sqlite', new PatternSearchDriver);

        $this->expectException(UnservableSearchException::class);
        $this->expectExceptionMessage(
            'The connection serving this resource reports no name, so the search driver cannot prove an index '
            . 'serves the "substring" match strategy and the proof cannot be waived either, since the waiver '
            . 'is a list of connection names.',
        );

        $this->applier->apply(User::on(self::NAMELESS_CONNECTION), $this->term(), SearchableFilterableUserResource::class);
    }

    /**
     * Test that a waiver of the wrong shape is read as no waiver at all.
     *
     * @return void
     */
    public function testMalformedWaiverIsReadAsNoWaiver(): void
    {
        Config::set('api-toolkit.search.unverified_connections', $this->connectionName());

        $this->registerDriver();

        $this->expectException(UnservableSearchException::class);

        $this->applier->apply(User::query(), $this->term(), SearchableFilterableUserResource::class);
    }

    /**
     * Register a search driver for the connection under test.
     *
     * @param  \Tests\Fixtures\Search\PatternSearchDriver|null  $driver
     * @return void
     */
    private function registerDriver(?PatternSearchDriver $driver = null): void
    {
        $this->drivers->register($this->driver(), $driver ?? new PatternSearchDriver);
    }

    /**
     * Define two connections speaking the same engine as the one the suite runs
     * against, so the waiver has a pair to tell apart.
     *
     * @return void
     */
    private function defineSiblingConnections(): void
    {
        $config = Config::get('database.connections.' . $this->connectionName());

        Config::set('database.connections.' . self::WAIVED_CONNECTION, $config);
        Config::set('database.connections.' . self::UNWAIVED_CONNECTION, $config);
    }

    /**
     * Define a connection resolved by an extension that reports no name of its
     * own, which the framework's own factory never produces.
     *
     * @return void
     */
    private function defineNamelessConnection(): void
    {
        Config::set('database.connections.' . self::NAMELESS_CONNECTION, ['driver' => 'sqlite', 'database' => ':memory:']);

        DB::extend(self::NAMELESS_CONNECTION, fn (): Connection => new SQLiteConnection(
            fn (): \PDO => new \PDO('sqlite::memory:'),
            'main',
            '',
            ['driver' => 'sqlite'],
        ));
    }

    /**
     * Apply the term to a fresh user query through a registered driver.
     *
     * @param  string|null  $resourceClass
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function applySearch(?string $resourceClass = null): Builder
    {
        $this->registerDriver();

        $query = User::query();

        $this->applier->apply($query, $this->term(), $resourceClass ?? SearchableFilterableUserResource::class);

        return $query;
    }

    /**
     * Build the term every test searches for.
     *
     * @return \SineMacula\ApiToolkit\Search\SearchTerm
     */
    private function term(): SearchTerm
    {
        return SearchTerm::from(self::TERM);
    }

    /**
     * Return the engine the connection under test speaks, which is the name a
     * driver is registered against.
     *
     * @return string
     */
    private function driver(): string
    {
        return (new User)->getConnection()->getDriverName();
    }

    /**
     * Return the name of the connection under test, which is the name the
     * waiver is keyed by.
     *
     * @return string
     */
    private function connectionName(): string
    {
        return (new User)->getConnection()->getName() ?? '';
    }
}
