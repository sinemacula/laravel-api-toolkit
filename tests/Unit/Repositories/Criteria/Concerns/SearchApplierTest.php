<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
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

        Config::set('api-toolkit.search.unverified_connections', [$this->connection()]);
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
        $query = $this->applySearch();

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
        $query = $this->applySearch();

        self::assertSame(['%' . self::TERM . '%', self::TERM . '%'], $query->getQuery()->getBindings());
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
        $this->expectExceptionMessage(sprintf('No search driver is registered for the "%s" connection. Register one to serve a search on that connection.', $this->connection()));

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
            'The search driver registered for the "%s" connection does not implement the "prefix" match strategy this resource declares.',
            $this->connection(),
        ));

        $this->applier->apply(User::query(), $this->term(), SearchableFilterableUserResource::class);
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
            'The search driver registered for the "%s" connection cannot prove an index serves the "substring" match strategy, so the search would scan the table. '
            . 'List the connection under api-toolkit.search.unverified_connections to serve it regardless.',
            $this->connection(),
        ));

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
     * Test that a waiver of the wrong shape is read as no waiver at all.
     *
     * @return void
     */
    public function testMalformedWaiverIsReadAsNoWaiver(): void
    {
        Config::set('api-toolkit.search.unverified_connections', $this->connection());

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
        $this->drivers->register($this->connection(), $driver ?? new PatternSearchDriver);
    }

    /**
     * Apply the term to a fresh user query through a registered driver.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Tests\Fixtures\Models\User>
     */
    private function applySearch(): Builder
    {
        $this->registerDriver();

        $query = User::query();

        $this->applier->apply($query, $this->term(), SearchableFilterableUserResource::class);

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
     * Return the driver name of the connection the suite runs against.
     *
     * @return string
     */
    private function connection(): string
    {
        return (new User)->getConnection()->getDriverName();
    }
}
