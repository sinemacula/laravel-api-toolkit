<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException;
use SineMacula\ApiToolkit\Exceptions\UnservableSearchException;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier;
use SineMacula\ApiToolkit\Search\IndexProof;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\EqualitySearchableUserResource;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\Fixtures\Resources\SearchableUserResource;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\Fixtures\Search\StubEngineSearchDriver;
use Tests\TestCase;

/**
 * Tests for the SearchApplier.
 *
 * The test connection cannot prove an index, so each test sets its waiver.
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
        $this->applier = new SearchApplier($this->drivers, $this->app->make(IndexProof::class)); // @phpstan-ignore method.nonObject

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
     * Test that the whole search is applied as one nested group.
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
     * Test that each declared strategy contributes its own OR-ed group.
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
     * Test that each column is bound through its own strategy's pattern.
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
     * Test that a resource declaring nothing searchable refuses the parameter.
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
     * Test that a non-API-resource class refuses the parameter.
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
     * Test that a connection with no registered driver fails.
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
     * Test that a driver asked for an unsupported strategy throws.
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
     * Test that a driver unable to prove an index is refused without a waiver.
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
     * Test that a driver refusing the strategies together is refused whole.
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
     * Test that a driver's index proof is read on the request path.
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
     * Test that a later operation reuses the first operation's index proof.
     *
     * @return void
     */
    public function testALaterOperationReadsNoCatalogue(): void
    {
        Config::set('api-toolkit.search.unverified_connections', []);

        $this->drivers->register($this->driver(), new StubEngineSearchDriver);

        $statements = [];

        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $served = $this->serves(EqualitySearchableUserResource::class);

        self::assertNotSame([], $statements);

        $statements = [];

        $this->app->make(CacheManager::class)->flush(); // @phpstan-ignore method.nonObject
        $this->app->forgetScopedInstances(); // @phpstan-ignore method.nonObject

        self::assertSame($served, $this->serves(EqualitySearchableUserResource::class));
        self::assertSame([], $statements);
    }

    /**
     * Test that a waived connection does not go on to read a proof.
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
     * Test that the waiver excuses only a driver that cannot prove anything.
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
     * Test that the waiver names a connection rather than its engine.
     *
     * Two connections on the same engine are waived apart by name alone.
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
     * Test that a connection reporting no name of its own is never waived.
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
     * Define two connections on the engine the suite runs against.
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
     * Define a connection whose resolver reports no name of its own.
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
     * Determine whether the applier serves a search against the resource.
     *
     * @param  string  $resourceClass
     * @return bool
     */
    private function serves(string $resourceClass): bool
    {
        try {
            $this->applier->apply(User::query(), $this->term(), $resourceClass);
        } catch (UnservableSearchException) {
            return false;
        }

        return true;
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
     * Return the engine of the connection under test.
     *
     * @return string
     */
    private function driver(): string
    {
        return (new User)->getConnection()->getDriverName();
    }

    /**
     * Return the name of the connection under test.
     *
     * @return string
     */
    private function connectionName(): string
    {
        return (new User)->getConnection()->getName() ?? '';
    }
}
