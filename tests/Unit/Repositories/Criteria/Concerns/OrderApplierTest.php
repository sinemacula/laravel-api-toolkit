<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
use SineMacula\ApiToolkit\Search\IndexProof;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use SineMacula\ApiToolkit\Search\SearchTerm;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\TestCase;

/**
 * Tests for the OrderApplier concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OrderApplier::class)]
final class OrderApplierTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier */
    private OrderApplier $applier;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        $this->applier = new OrderApplier($this->app->make(SchemaIntrospectionProvider::class), $this->searchApplier());
    }

    /**
     * Test that apply with an empty order array returns the query unmodified.
     *
     * @return void
     */
    public function testApplyWithEmptyOrderReturnsUnmodifiedQuery(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, [], $this->surface());

        self::assertEmpty($result->getQuery()->orders ?? []);
    }

    /**
     * Test that apply with a single column adds one orderBy clause.
     *
     * @return void
     */
    public function testApplyWithSingleColumnAddsOrderBy(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['name' => 'asc'], $this->surface());

        $orders = $result->getQuery()->orders ?? [];

        self::assertCount(1, $orders);
        self::assertSame('name', $orders[0]['column']);
        self::assertSame('asc', $orders[0]['direction']);
    }

    /**
     * Test that apply adds an orderBy clause per column, in order.
     *
     * @return void
     */
    public function testApplyWithMultipleColumnsAddsMultipleOrderBy(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['name' => 'asc', 'email' => 'desc'], $this->surface());

        $orders = $result->getQuery()->orders ?? [];

        self::assertCount(2, $orders);
        self::assertSame('name', $orders[0]['column']);
        self::assertSame('asc', $orders[0]['direction']);
        self::assertSame('email', $orders[1]['column']);
        self::assertSame('desc', $orders[1]['direction']);
    }

    /**
     * Test that the random keyword applies inRandomOrder once enabled.
     *
     * @return void
     */
    public function testApplyWithRandomOrderAppliesInRandomOrder(): void
    {
        Config::set('api-toolkit.repositories.allow_random_order', true);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['random' => 'asc'], $this->surface());

        $orders = $result->getQuery()->orders ?? [];

        self::assertNotEmpty($orders);
        self::assertSame('RANDOM()', $orders[0]['sql'] ?? $orders[0]['column'] ?? '');
    }

    /**
     * Test that the random keyword is sort-guarded while disabled.
     *
     * @return void
     */
    public function testApplyWithRandomOrderIsGuardedWhileTheCapabilityIsDisabled(): void
    {
        $query = (new User)->newQuery();

        $this->assertRejects(fn () => $this->applier->apply($query, ['random' => 'asc'], $this->surface()), 'random');
    }

    /**
     * Test that the random keyword is disabled when the config is absent.
     *
     * @return void
     */
    public function testApplyWithRandomOrderIsDisabledWhenTheConfigurationIsAbsent(): void
    {
        Config::set('api-toolkit.repositories', []);

        $query = (new User)->newQuery();

        $this->assertRejects(fn () => $this->applier->apply($query, ['random' => 'asc'], $this->surface()), 'random');
    }

    /**
     * Test that a truthy string config value enables the capability.
     *
     * @return void
     */
    public function testApplyWithRandomOrderHonoursATruthyConfiguredValue(): void
    {
        Config::set('api-toolkit.repositories.allow_random_order', '1');

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['random' => 'asc'], $this->surface());

        self::assertNotEmpty($result->getQuery()->orders ?? []);
    }

    /**
     * Test that apply with an undeclared column rejects the request.
     *
     * @return void
     */
    public function testApplyWithUndeclaredColumnIsRejected(): void
    {
        $query = (new User)->newQuery();

        $this->assertRejects(fn () => $this->applier->apply($query, ['nonexistent' => 'asc'], $this->surface()), 'nonexistent');
    }

    /**
     * Test that apply applies both random and regular ordering.
     *
     * @return void
     */
    public function testApplyWithRandomAndRegularColumnsAppliesBoth(): void
    {
        Config::set('api-toolkit.repositories.allow_random_order', true);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['random' => 'asc', 'name' => 'desc'], $this->surface());

        $orders = $result->getQuery()->orders ?? [];

        self::assertCount(2, $orders);
        self::assertSame('RANDOM()', $orders[0]['sql'] ?? $orders[0]['column'] ?? '');
        self::assertSame('name', $orders[1]['column']);
        self::assertSame('desc', $orders[1]['direction']);
    }

    /**
     * Test that random order is refused on a cursor-paginated read.
     *
     * @return void
     */
    public function testRandomOrderIsRejectedUnderCursorPagination(): void
    {
        Config::set('api-toolkit.repositories.allow_random_order', true);

        $this->paginateByCursor();

        $query = (new User)->newQuery();

        $this->assertRejectsWith(
            fn () => $this->applier->apply($query, ['random' => 'asc'], $this->surface()),
            'random',
            'Random order cannot be paged by cursor; use offset pagination.',
        );
    }

    /**
     * Test that a nullable column is refused on a cursor-paginated read.
     *
     * @return void
     */
    public function testNullableColumnIsRejectedUnderCursorPagination(): void
    {
        $this->paginateByCursor();

        $query = (new User)->newQuery();

        $this->assertRejectsWith(
            fn () => $this->applier->apply($query, ['name' => 'asc', 'created_at' => 'desc'], $this->surface()),
            'created_at',
            'The "created_at" key admits null, so it cannot order a cursor-paginated read; use offset pagination.',
        );
    }

    /**
     * Test that a column that does not admit null orders a cursor read.
     *
     * @return void
     */
    public function testNonNullableColumnOrdersACursorRead(): void
    {
        $this->paginateByCursor();

        $result = $this->applier->apply((new User)->newQuery(), ['name' => 'desc'], $this->surface());

        self::assertSame([['column' => 'name', 'direction' => 'desc']], $result->getQuery()->orders);
    }

    /**
     * Test that a column the catalogue does not list orders a cursor read.
     *
     * @return void
     */
    public function testColumnAbsentFromTheCatalogueOrdersACursorRead(): void
    {
        $this->paginateByCursor();

        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumnDefinitions')->willReturn(['name' => new ColumnDefinition('name', 'varchar', true)]);

        $result = (new OrderApplier($introspector, $this->searchApplier()))->apply((new User)->newQuery(), ['email' => 'asc'], $this->surface());

        self::assertSame([['column' => 'email', 'direction' => 'asc']], $result->getQuery()->orders);
    }

    /**
     * Test that random order and nullable columns still order an offset read,
     * without reading the column catalogue.
     *
     * @return void
     */
    public function testOffsetReadOrdersByRandomAndNullableColumnsWithoutReadingTheCatalogue(): void
    {
        Config::set('api-toolkit.repositories.allow_random_order', true);

        ApiQuery::parse(Request::create('/', HttpMethod::GET->getVerb(), ['page' => '2']));

        $introspector = $this->createMock(SchemaIntrospectionProvider::class);
        $introspector->expects(self::never())->method('getColumnDefinitions');

        $result = (new OrderApplier($introspector, $this->searchApplier()))->apply((new User)->newQuery(), ['random' => 'asc', 'created_at' => 'desc'], $this->surface());

        $orders = $result->getQuery()->orders ?? [];

        self::assertCount(2, $orders);
        self::assertSame(['column' => 'created_at', 'direction' => 'desc'], $orders[1]);
    }

    /**
     * Test that the ORDER_BY_RANDOM constant equals 'random'.
     *
     * @return void
     */
    public function testOrderByRandomConstantValue(): void
    {
        self::assertSame('random', OrderApplier::ORDER_BY_RANDOM);
    }

    /**
     * Test that relevance ranks the search matches at its place in the order,
     * though no column of that name is sortable.
     *
     * @return void
     */
    public function testRelevanceRanksTheMatchesAtItsPlaceInTheOrder(): void
    {
        $query = (new User)->newQuery();

        $order = ['name' => 'asc', 'relevance' => 'asc', 'email' => 'desc'];

        $this->applier->apply($query, $order, $this->surface(), SearchTerm::from('smith'), SearchableFilterableUserResource::class);

        self::assertMatchesRegularExpression(
            '/^select \* from "users" order by "name" asc, case when "users"\."name" like \? .* end asc, "email" desc$/',
            $query->toSql(), // @phpstan-ignore staticMethod.dynamicCall
        );
    }

    /**
     * Test that relevance is refused without a search term to rank by.
     *
     * @return void
     */
    public function testRelevanceIsRejectedWithoutASearchTerm(): void
    {
        $this->assertRejectsWith(
            fn () => $this->applier->apply((new User)->newQuery(), ['relevance' => 'asc'], $this->surface(), null, SearchableFilterableUserResource::class),
            'relevance',
            'The relevance order ranks search matches, so it needs a search term.',
        );
    }

    /**
     * Test that relevance is refused on a cursor-paginated read.
     *
     * @return void
     */
    public function testRelevanceIsRejectedUnderCursorPagination(): void
    {
        $this->paginateByCursor();

        $this->assertRejectsWith(
            fn () => $this->applier->apply((new User)->newQuery(), ['relevance' => 'asc'], $this->surface(), SearchTerm::from('smith'), SearchableFilterableUserResource::class),
            'relevance',
            'Relevance order cannot be paged by cursor; use offset pagination.',
        );
    }

    /**
     * Test that relevance is refused in a descending direction.
     *
     * @return void
     */
    public function testRelevanceIsRejectedInADescendingDirection(): void
    {
        $this->assertRejectsWith(
            fn () => $this->applier->apply((new User)->newQuery(), ['relevance' => 'desc'], $this->surface(), SearchTerm::from('smith'), SearchableFilterableUserResource::class),
            'relevance',
            'The relevance key accepts asc only.',
        );
    }

    /**
     * Test that relevance is always a keyword, and random only once enabled.
     *
     * @return void
     */
    public function testRelevanceIsAlwaysAKeywordAndRandomOnlyOnceEnabled(): void
    {
        self::assertTrue(OrderApplier::isKeyword('relevance'));
        self::assertFalse(OrderApplier::isKeyword('random'));
        self::assertFalse(OrderApplier::isKeyword('name'));

        Config::set('api-toolkit.repositories.allow_random_order', true);

        self::assertTrue(OrderApplier::isKeyword('random'));
    }

    /**
     * Build a query surface declaring the sortable columns these tests use.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function surface(): QuerySurface
    {
        return new QuerySurface(
            new CompiledSchema([], sortableColumns: ['name', 'email', 'created_at']),
            new RelationTraversal(app(SchemaIntrospectionProvider::class), []),
        );
    }

    /**
     * Build a search applier over the registered drivers.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier
     */
    private function searchApplier(): SearchApplier
    {
        assert($this->app !== null);

        return new SearchApplier($this->app->make(SearchDriverRegistry::class), $this->app->make(IndexProof::class));
    }

    /**
     * Parse a cursor-paginated request into the query parser.
     *
     * @return void
     */
    private function paginateByCursor(): void
    {
        ApiQuery::parse(Request::create('/', HttpMethod::GET->getVerb(), ['pagination' => 'cursor']));
    }

    /**
     * Assert that applying the order rejects the column.
     *
     * @param  callable(): mixed  $apply
     * @param  string  $column
     * @return void
     */
    private function assertRejects(callable $apply, string $column): void
    {
        $this->assertRejectsWith($apply, $column, 'The "' . $column . '" key is not a permitted query parameter for this resource.');
    }

    /**
     * Assert that applying the order rejects the column with the message.
     *
     * @param  callable(): mixed  $apply
     * @param  string  $column
     * @param  string  $message
     * @return void
     */
    private function assertRejectsWith(callable $apply, string $column, string $message): void
    {
        try {
            $apply();
            self::fail('Expected a ValidationException for the "' . $column . '" order key.');
        } catch (ValidationException $exception) {
            self::assertSame(['order.' . $column => [$message]], $exception->errors());
        }
    }
}
