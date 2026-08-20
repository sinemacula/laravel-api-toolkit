<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Fixtures\Models\User;
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
    /** @var \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider */
    private SchemaIntrospectionProvider $schemaIntrospector;

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

        $this->schemaIntrospector = self::createStub(SchemaIntrospectionProvider::class);
        $this->schemaIntrospector->method('isSearchable')->willReturnCallback(
            fn (Model $model, string $column) => in_array($column, ['name', 'email', 'created_at'], true),
        );

        $this->applier = new OrderApplier;
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
     * Test that apply with multiple columns adds multiple orderBy clauses in
     * order.
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
     * Test that apply with the random keyword calls inRandomOrder once the
     * capability is enabled.
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
     * Test that the random keyword is passed to the sort guard while the
     * capability is disabled, so it is skipped like any undeclared column
     * rather than bypassing the guard.
     *
     * @return void
     */
    public function testApplyWithRandomOrderIsGuardedWhileTheCapabilityIsDisabled(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['random' => 'asc'], $this->surface());

        self::assertEmpty($result->getQuery()->orders ?? []);
    }

    /**
     * Test that the random keyword stays disabled when the repository
     * configuration is absent entirely, so an unpublished config cannot enable
     * the capability by omission.
     *
     * @return void
     */
    public function testApplyWithRandomOrderIsDisabledWhenTheConfigurationIsAbsent(): void
    {
        Config::set('api-toolkit.repositories', []);

        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['random' => 'asc'], $this->surface());

        self::assertEmpty($result->getQuery()->orders ?? []);
    }

    /**
     * Test that a truthy configured value enables the capability, so a value
     * arriving from the environment as a string still applies.
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
     * Test that apply with an invalid direction silently skips the column.
     *
     * @return void
     */
    public function testApplyWithInvalidDirectionSkipsColumn(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['name' => 'invalid'], $this->surface());

        self::assertEmpty($result->getQuery()->orders ?? []);
    }

    /**
     * Test that apply with a non-searchable column silently skips it.
     *
     * @return void
     */
    public function testApplyWithNonSearchableColumnSkipsColumn(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['nonexistent' => 'asc'], $this->surface());

        self::assertEmpty($result->getQuery()->orders ?? []);
    }

    /**
     * Test that apply with both random and regular columns applies both
     * ordering types.
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
     * Test that a column failing the sort guard is skipped without halting the
     * loop, so a valid column declared after it is still applied.
     *
     * @return void
     */
    public function testAppliesValidColumnAfterSkippingAnInvalidOne(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['nonexistent' => 'asc', 'name' => 'asc'], $this->surface());

        $orders = $result->getQuery()->orders ?? [];

        self::assertCount(1, $orders);
        self::assertSame('name', $orders[0]['column']);
        self::assertSame('asc', $orders[0]['direction']);
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
     * Build a blocklist query surface that delegates to the stubbed
     * introspector, preserving the legacy searchable gating these tests assert.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function surface(): QuerySurface
    {
        return new QuerySurface([], [], [], QuerySurface::POSTURE_BLOCKLIST, $this->schemaIntrospector, new User);
    }
}
