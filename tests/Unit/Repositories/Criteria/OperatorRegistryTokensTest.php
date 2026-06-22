<?php

namespace Tests\Unit\Repositories\Criteria;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use Illuminate\Database\Eloquent\Builder;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext;

/**
 * Tests for the OperatorRegistry::tokens() method.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(OperatorRegistry::class)]
class OperatorRegistryTokensTest extends TestCase
{
    /**
     * Test that tokens returns an empty array when no operators are registered.
     *
     * @return void
     */
    public function testTokensReturnsEmptyArrayWhenNoOperatorsRegistered(): void
    {
        $registry = new OperatorRegistry;

        static::assertSame([], $registry->tokens());
    }

    /**
     * Test that tokens returns all registered operator token strings.
     *
     * @return void
     */
    public function testTokensReturnsAllRegisteredTokens(): void
    {
        $registry = new OperatorRegistry;

        $registry->register('$eq', $this->makeStub());
        $registry->register('$neq', $this->makeStub());
        $registry->register('$gt', $this->makeStub());

        static::assertSame(['$eq', '$neq', '$gt'], $registry->tokens());
    }

    /**
     * Test that tokens reflects a removed operator immediately.
     *
     * @return void
     */
    public function testTokensReflectsRemoval(): void
    {
        $registry = new OperatorRegistry;

        $registry->register('$eq', $this->makeStub());
        $registry->register('$neq', $this->makeStub());
        $registry->remove('$eq');

        static::assertSame(['$neq'], array_values($registry->tokens()));
    }

    /**
     * Test that tokens reflects an overridden operator (token stays present).
     *
     * @return void
     */
    public function testTokensReflectsOverride(): void
    {
        $registry = new OperatorRegistry;

        $registry->register('$eq', $this->makeStub());
        $registry->override('$eq', $this->makeStub());

        static::assertSame(['$eq'], $registry->tokens());
    }

    /**
     * Create a minimal no-op stub for the FilterOperator contract.
     *
     * @return \SineMacula\ApiToolkit\Contracts\FilterOperator
     */
    private function makeStub(): FilterOperator
    {
        /**
         * No-op stub FilterOperator for tokens tests.
         *
         * @author      Ben Carey <bdmc@sinemacula.co.uk>
         * @copyright   2026 Sine Macula Limited.
         */
        return new class implements FilterOperator {
            /**
             * No-op apply for stub.
             *
             * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
             * @param  string  $column
             * @param  mixed  $value
             * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterContext  $context
             * @return void
             */
            public function apply(Builder $query, string $column, mixed $value, FilterContext $context,): void {}
        };
    }
}
