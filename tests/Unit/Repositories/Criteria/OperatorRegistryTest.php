<?php

namespace Tests\Unit\Repositories\Criteria;

use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the OperatorRegistry class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 * @SuppressWarnings("php:S1172")
 *
 * @internal
 */
#[CoversClass(OperatorRegistry::class)]
class OperatorRegistryTest extends TestCase
{
    /** @var string */
    private const string OPERATOR_CUSTOM = '$custom';

    /** @var \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry */
    private OperatorRegistry $registry;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new OperatorRegistry;
    }

    /**
     * Test that register stores an operator and it can be resolved.
     *
     * @return void
     */
    public function testRegisterStoresOperatorForToken(): void
    {
        $operator = $this->createStubOperator();

        $this->registry->register('$eq', $operator);

        static::assertTrue($this->registry->has('$eq'));
        static::assertSame($operator, $this->registry->resolve('$eq'));
    }

    /**
     * Test that register throws when the token is already registered.
     *
     * @return void
     */
    public function testRegisterThrowsWhenTokenAlreadyRegistered(): void
    {
        $this->registry->register('$eq', $this->createStubOperator());

        $this->expectException(\InvalidArgumentException::class);

        $this->registry->register('$eq', $this->createStubOperator());
    }

    /**
     * Test that override replaces an existing handler.
     *
     * @return void
     */
    public function testOverrideReplacesExistingHandler(): void
    {
        $original    = $this->createStubOperator();
        $replacement = $this->createStubOperator();

        $this->registry->register('$eq', $original);
        $this->registry->override('$eq', $replacement);

        static::assertSame($replacement, $this->registry->resolve('$eq'));
    }

    /**
     * Test that override registers when the token is not present.
     *
     * @return void
     */
    public function testOverrideRegistersWhenTokenNotPresent(): void
    {
        $operator = $this->createStubOperator();

        $this->registry->override('$eq', $operator);

        static::assertTrue($this->registry->has('$eq'));
        static::assertSame($operator, $this->registry->resolve('$eq'));
    }

    /**
     * Test that remove deregisters a token.
     *
     * @return void
     */
    public function testRemoveDeregistersToken(): void
    {
        $this->registry->register('$eq', $this->createStubOperator());
        $this->registry->remove('$eq');

        static::assertFalse($this->registry->has('$eq'));
        static::assertNull($this->registry->resolve('$eq'));
    }

    /**
     * Test that remove is a no-op for an unregistered token.
     *
     * @return void
     */
    public function testRemoveIsNoOpForUnregisteredToken(): void
    {
        $this->registry->remove('$nonexistent');

        static::assertFalse($this->registry->has('$nonexistent'));
    }

    /**
     * Test that resolve returns null for an unregistered token.
     *
     * @return void
     */
    public function testResolveReturnsNullForUnregisteredToken(): void
    {
        static::assertNull($this->registry->resolve('$unknown'));
    }

    /**
     * Test that has returns false for an unregistered token.
     *
     * @return void
     */
    public function testHasReturnsFalseForUnregisteredToken(): void
    {
        static::assertFalse($this->registry->has('$unknown'));
    }

    /**
     * Test that register with a closure wraps it in a FilterOperator.
     *
     * @return void
     */
    public function testRegisterWithClosureWrapsInFilterOperator(): void
    {
        $this->registry->register(self::OPERATOR_CUSTOM, function (Builder $query, string $column, mixed $value, FilterContext $context): void {
            $query->where($column, '=', $value);
        });

        $resolved = $this->registry->resolve(self::OPERATOR_CUSTOM);

        static::assertInstanceOf(FilterOperator::class, $resolved);

        $query = (new User)->newQuery();

        $resolved->apply($query, 'name', 'Alice', FilterContext::root());

        $wheres = $query->getQuery()->wheres;

        static::assertNotEmpty($wheres);
        static::assertSame('name', $wheres[0]['column']);
        static::assertSame('Alice', $wheres[0]['value']);
    }

    /**
     * Test that override with a closure wraps it in a FilterOperator.
     *
     * @return void
     */
    public function testOverrideWithClosureWrapsInFilterOperator(): void
    {
        $this->registry->override(self::OPERATOR_CUSTOM, function (Builder $query, string $column, mixed $value, FilterContext $context): void {
            $query->where($column, '=', $value);
        });

        $resolved = $this->registry->resolve(self::OPERATOR_CUSTOM);

        static::assertInstanceOf(FilterOperator::class, $resolved);
    }

    /**
     * Test that the exception message from duplicate registration
     * contains the token string.
     *
     * @return void
     */
    public function testRegisterThrowsExceptionMessageIncludesToken(): void
    {
        $this->registry->register('$eq', $this->createStubOperator());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Operator "$eq" is already registered. Use override() to replace it.');

        $this->registry->register('$eq', $this->createStubOperator());
    }

    /**
     * Create a stub FilterOperator implementation for testing.
     *
     * @return \SineMacula\ApiToolkit\Contracts\FilterOperator
     */
    private function createStubOperator(): FilterOperator
    {
        return new class implements FilterOperator {
            /**
             * No-op operator for testing.
             *
             * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
             * @param  string  $column
             * @param  mixed  $value
             * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
             * @return void
             */
            public function apply(Builder $query, string $column, mixed $value, FilterContext $context): void
            {
                // Intentionally empty — stub for registry tests
            }
        };
    }
}
