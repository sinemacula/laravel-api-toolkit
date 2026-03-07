<?php

namespace Tests\Unit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\OrderApplier;
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
class OrderApplierTest extends TestCase
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

        $this->schemaIntrospector = $this->createMock(SchemaIntrospectionProvider::class);
        $this->schemaIntrospector->method('isSearchable')->willReturnCallback(
            fn (Model $model, string $column) => in_array($column, ['name', 'email', 'created_at'], true),
        );

        $this->applier = new OrderApplier;
    }

    /**
     * Test that apply with an empty order array returns the query
     * unmodified.
     *
     * @return void
     */
    public function testApplyWithEmptyOrderReturnsUnmodifiedQuery(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, [], $this->schemaIntrospector);

        static::assertEmpty($result->getQuery()->orders ?? []);
    }

    /**
     * Test that apply with a single column adds one orderBy clause.
     *
     * @return void
     */
    public function testApplyWithSingleColumnAddsOrderBy(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['name' => 'asc'], $this->schemaIntrospector);

        $orders = $result->getQuery()->orders ?? [];

        static::assertCount(1, $orders);
        static::assertSame('name', $orders[0]['column']);
        static::assertSame('asc', $orders[0]['direction']);
    }

    /**
     * Test that apply with multiple columns adds multiple orderBy
     * clauses in order.
     *
     * @return void
     */
    public function testApplyWithMultipleColumnsAddsMultipleOrderBy(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['name' => 'asc', 'email' => 'desc'], $this->schemaIntrospector);

        $orders = $result->getQuery()->orders ?? [];

        static::assertCount(2, $orders);
        static::assertSame('name', $orders[0]['column']);
        static::assertSame('asc', $orders[0]['direction']);
        static::assertSame('email', $orders[1]['column']);
        static::assertSame('desc', $orders[1]['direction']);
    }

    /**
     * Test that apply with the random keyword calls inRandomOrder.
     *
     * @return void
     */
    public function testApplyWithRandomOrderAppliesInRandomOrder(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['random' => 'asc'], $this->schemaIntrospector);

        $orders = $result->getQuery()->orders ?? [];

        static::assertNotEmpty($orders);
        static::assertSame('RANDOM()', $orders[0]['sql'] ?? $orders[0]['column'] ?? '');
    }

    /**
     * Test that apply with an invalid direction silently skips the
     * column.
     *
     * @return void
     */
    public function testApplyWithInvalidDirectionSkipsColumn(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['name' => 'invalid'], $this->schemaIntrospector);

        static::assertEmpty($result->getQuery()->orders ?? []);
    }

    /**
     * Test that apply with a non-searchable column silently skips
     * it.
     *
     * @return void
     */
    public function testApplyWithNonSearchableColumnSkipsColumn(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['nonexistent' => 'asc'], $this->schemaIntrospector);

        static::assertEmpty($result->getQuery()->orders ?? []);
    }

    /**
     * Test that apply with both random and regular columns applies
     * both ordering types.
     *
     * @return void
     */
    public function testApplyWithRandomAndRegularColumnsAppliesBoth(): void
    {
        $query  = (new User)->newQuery();
        $result = $this->applier->apply($query, ['random' => 'asc', 'name' => 'desc'], $this->schemaIntrospector);

        $orders = $result->getQuery()->orders ?? [];

        static::assertCount(2, $orders);
        static::assertSame('RANDOM()', $orders[0]['sql'] ?? $orders[0]['column'] ?? '');
        static::assertSame('name', $orders[1]['column']);
        static::assertSame('desc', $orders[1]['direction']);
    }

    /**
     * Test that the ORDER_BY_RANDOM constant equals 'random'.
     *
     * @return void
     */
    public function testOrderByRandomConstantValue(): void
    {
        static::assertSame('random', OrderApplier::ORDER_BY_RANDOM);
    }
}
