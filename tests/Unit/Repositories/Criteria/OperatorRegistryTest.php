<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria;

use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Contracts\FilterOperator;
use SineMacula\ApiToolkit\Enums\Capability;
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
final class OperatorRegistryTest extends TestCase
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

        $this->registry->register('$eq', $operator, Capability::EXACT);

        self::assertTrue($this->registry->has('$eq'));
        self::assertSame($operator, $this->registry->resolve('$eq'));
    }

    /**
     * Test that a token is admitted only by the capabilities it was registered
     * with.
     *
     * @return void
     */
    public function testAdmitsOnlyTheRegisteredCapabilities(): void
    {
        $this->registry->register(self::OPERATOR_CUSTOM, $this->createStubOperator(), Capability::RANGE, Capability::ENUM);

        $admitted = array_filter(Capability::cases(), fn (Capability $capability): bool => $this->registry->admits(self::OPERATOR_CUSTOM, $capability));

        self::assertSame([Capability::ENUM, Capability::RANGE], array_values($admitted));
    }

    /**
     * Test that an unregistered token is admitted by no capability.
     *
     * @return void
     */
    public function testAdmitsNoCapabilityForAnUnregisteredToken(): void
    {
        $this->registry->register('$eq', $this->createStubOperator(), ...Capability::cases());

        foreach (Capability::cases() as $capability) {
            self::assertFalse($this->registry->admits(self::OPERATOR_CUSTOM, $capability));
        }
    }

    /**
     * Test that a capability given twice is stored once.
     *
     * @return void
     */
    public function testRegisterDeduplicatesCapabilities(): void
    {
        $this->registry->register(self::OPERATOR_CUSTOM, $this->createStubOperator(), Capability::EXACT, Capability::EXACT, Capability::RANGE);

        self::assertSame([self::OPERATOR_CUSTOM], $this->registry->tokensFor(Capability::EXACT));
        self::assertSame([self::OPERATOR_CUSTOM], $this->registry->tokensFor(Capability::RANGE));
        self::assertSame([], $this->registry->tokensFor(Capability::OPAQUE));
    }

    /**
     * Test that tokensFor lists a capability's tokens in registration order.
     *
     * @return void
     */
    public function testTokensForListsTheCapabilityTokensInRegistrationOrder(): void
    {
        $this->registry->register('$gt', $this->createStubOperator(), Capability::RANGE);
        $this->registry->register('$eq', $this->createStubOperator(), Capability::EXACT, Capability::RANGE);
        $this->registry->register('$neq', $this->createStubOperator(), Capability::ENUM);

        self::assertSame(['$gt', '$eq'], $this->registry->tokensFor(Capability::RANGE));
        self::assertSame(['$eq'], $this->registry->tokensFor(Capability::EXACT));
    }

    /**
     * Provide tokens the filter grammar cannot register.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTokenProvider(): iterable
    {
        $structural = ' is a structural filter token and cannot be registered.';
        $malformed  = ' must be a dollar sign followed by a letter, then letters, digits or underscores.';

        yield 'and group' => ['$and', 'Operator "$and"' . $structural];
        yield 'or group' => ['$or', 'Operator "$or"' . $structural];
        yield 'has relation' => ['$has', 'Operator "$has"' . $structural];
        yield 'hasnt relation' => ['$hasnt', 'Operator "$hasnt"' . $structural];
        yield 'column name' => ['name', 'Operator "name"' . $malformed];
        yield 'bare dollar' => ['$', 'Operator "$"' . $malformed];
        yield 'leading digit' => ['$1x', 'Operator "$1x"' . $malformed];
        yield 'hyphen' => ['$a-b', 'Operator "$a-b"' . $malformed];
        yield 'trailing newline' => ["\$x\n", "Operator \"\$x\n\"" . $malformed];
    }

    /**
     * Test that a token outside the grammar is refused and nothing is stored.
     *
     * @param  string  $token
     * @param  string  $message
     * @return void
     */
    #[DataProvider('invalidTokenProvider')]
    public function testRegisterRefusesATokenOutsideTheGrammar(string $token, string $message): void
    {
        try {
            $this->registry->register($token, $this->createStubOperator(), Capability::EXACT);
            self::fail('The token was registered.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertFalse($this->registry->has($token));
        self::assertSame([], $this->registry->tokens());
        self::assertFalse($this->registry->admits($token, Capability::EXACT));
    }

    /**
     * Test that a taken token is refused and keeps its handler and
     * capabilities.
     *
     * @return void
     */
    public function testRegisterRefusesATakenToken(): void
    {
        $original = $this->createStubOperator();

        $this->registry->register('$eq', $original, Capability::EXACT);

        try {
            $this->registry->register('$eq', $this->createStubOperator(), Capability::RANGE);
            self::fail('The token was registered twice.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Operator "$eq" is already registered. Use override() to replace it.', $exception->getMessage());
        }

        self::assertSame($original, $this->registry->resolve('$eq'));
        self::assertFalse($this->registry->admits('$eq', Capability::RANGE));
    }

    /**
     * Test that one handler registered under two tokens keeps each token's
     * capabilities apart.
     *
     * @return void
     */
    public function testOneHandlerUnderTwoTokensKeepsEachTokensCapabilities(): void
    {
        $operator = $this->createStubOperator();

        $this->registry->register('$starts', $operator, Capability::RANGE);
        $this->registry->register('$prefix', $operator, Capability::EXACT);
        $this->registry->remove('$starts');

        self::assertSame($operator, $this->registry->resolve('$prefix'));
        self::assertTrue($this->registry->admits('$prefix', Capability::EXACT));
        self::assertFalse($this->registry->admits('$prefix', Capability::RANGE));
        self::assertSame([], $this->registry->tokensFor(Capability::RANGE));
    }

    /**
     * Test that override replaces the handler and keeps the capabilities.
     *
     * @return void
     */
    public function testOverrideReplacesTheHandlerAndKeepsTheCapabilities(): void
    {
        $replacement = $this->createStubOperator();

        $this->registry->register('$contains', $this->createStubOperator(), Capability::DOCUMENT);
        $this->registry->override('$contains', $replacement);

        self::assertSame($replacement, $this->registry->resolve('$contains'));
        self::assertSame(['$contains'], $this->registry->tokensFor(Capability::DOCUMENT));
        self::assertSame([], $this->registry->tokensFor(Capability::EXACT));
    }

    /**
     * Test that override refuses a token that was never registered.
     *
     * @return void
     */
    public function testOverrideRefusesAnUnregisteredToken(): void
    {
        try {
            $this->registry->override(self::OPERATOR_CUSTOM, $this->createStubOperator());
            self::fail('The token was overridden.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Operator "$custom" is not registered. Use register() to add it.', $exception->getMessage());
        }

        self::assertFalse($this->registry->has(self::OPERATOR_CUSTOM));
    }

    /**
     * Test that remove drops the handler and its capabilities.
     *
     * @return void
     */
    public function testRemoveDropsTheHandlerAndItsCapabilities(): void
    {
        $this->registry->register('$eq', $this->createStubOperator(), Capability::EXACT);
        $this->registry->remove('$eq');

        self::assertFalse($this->registry->has('$eq'));
        self::assertNull($this->registry->resolve('$eq'));
        self::assertFalse($this->registry->admits('$eq', Capability::EXACT));
        self::assertSame([], $this->registry->tokensFor(Capability::EXACT));
    }

    /**
     * Test that removing then registering a token gives it new capabilities.
     *
     * @return void
     */
    public function testRemoveThenRegisterChangesTheTokensCapabilities(): void
    {
        $this->registry->register('$neq', $this->createStubOperator(), Capability::ENUM);
        $this->registry->remove('$neq');
        $this->registry->register('$neq', $this->createStubOperator(), Capability::EXACT);

        self::assertTrue($this->registry->admits('$neq', Capability::EXACT));
        self::assertFalse($this->registry->admits('$neq', Capability::ENUM));
    }

    /**
     * Test that remove is a no-op for an unregistered token.
     *
     * @return void
     */
    public function testRemoveIsNoOpForUnregisteredToken(): void
    {
        $this->registry->remove('$nonexistent');

        self::assertFalse($this->registry->has('$nonexistent'));
    }

    /**
     * Test that resolve returns null for an unregistered token.
     *
     * @return void
     */
    public function testResolveReturnsNullForUnregisteredToken(): void
    {
        self::assertNull($this->registry->resolve('$unknown'));
    }

    /**
     * Test that register with a closure wraps it in a FilterOperator.
     *
     * @return void
     */
    public function testRegisterWithClosureWrapsInFilterOperator(): void
    {
        $this->registry->register(self::OPERATOR_CUSTOM, function (Builder $query, string $column, mixed $value): void {
            $query->where($column, '=', $value);
        }, Capability::EXACT);

        $resolved = $this->registry->resolve(self::OPERATOR_CUSTOM);

        self::assertInstanceOf(FilterOperator::class, $resolved);

        $query = (new User)->newQuery();

        $resolved->apply($query, 'name', 'Alice');

        $wheres = $query->getQuery()->wheres;

        self::assertNotEmpty($wheres);
        self::assertSame('name', $wheres[0]['column']);
        self::assertSame('Alice', $wheres[0]['value']);
    }

    /**
     * Test that override with a closure wraps it in a FilterOperator.
     *
     * @return void
     */
    public function testOverrideWithClosureWrapsInFilterOperator(): void
    {
        $this->registry->register(self::OPERATOR_CUSTOM, $this->createStubOperator(), Capability::EXACT);
        $this->registry->override(self::OPERATOR_CUSTOM, function (Builder $query, string $column, mixed $value): void {
            $query->where($column, '=', $value);
        });

        $query = (new User)->newQuery();

        $this->registry->resolve(self::OPERATOR_CUSTOM)?->apply($query, 'name', 'Alice');

        self::assertSame('Alice', $query->getQuery()->wheres[0]['value'] ?? null);
    }

    /**
     * Test that tokens is empty when no operators are registered.
     *
     * @return void
     */
    public function testTokensReturnsEmptyArrayWhenNoOperatorsRegistered(): void
    {
        self::assertSame([], (new OperatorRegistry)->tokens());
    }

    /**
     * Test that tokens lists the registered tokens in registration order.
     *
     * @return void
     */
    public function testTokensReturnsAllRegisteredTokens(): void
    {
        $this->registry->register('$eq', $this->createStubOperator(), Capability::EXACT);
        $this->registry->register('$neq', $this->createStubOperator(), Capability::ENUM);
        $this->registry->register('$gt', $this->createStubOperator(), Capability::RANGE);

        self::assertSame(['$eq', '$neq', '$gt'], $this->registry->tokens());
    }

    /**
     * Test that tokens reflects a removed operator immediately.
     *
     * @return void
     */
    public function testTokensReflectsRemoval(): void
    {
        $this->registry->register('$eq', $this->createStubOperator(), Capability::EXACT);
        $this->registry->register('$neq', $this->createStubOperator(), Capability::ENUM);
        $this->registry->remove('$eq');

        self::assertSame(['$neq'], array_values($this->registry->tokens()));
    }

    /**
     * Test that an overridden operator keeps its token.
     *
     * @return void
     */
    public function testTokensReflectsOverride(): void
    {
        $this->registry->register('$eq', $this->createStubOperator(), Capability::EXACT);
        $this->registry->override('$eq', $this->createStubOperator());

        self::assertSame(['$eq'], $this->registry->tokens());
    }

    /**
     * Create a stub FilterOperator implementation for testing.
     *
     * @return \SineMacula\ApiToolkit\Contracts\FilterOperator
     */
    private function createStubOperator(): FilterOperator
    {
        /**
         * Stub FilterOperator for testing.
         *
         * @author      Ben Carey <bdmc@sinemacula.co.uk>
         * @copyright   2026 Sine Macula Limited.
         */
        return new class implements FilterOperator {
            /**
             * No-op operator for testing.
             *
             * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
             * @param  string  $column
             * @param  mixed  $value
             * @return void
             */
            #[\Override]
            public function apply(Builder $query, string $column, mixed $value): void
            {
                // Intentionally empty
            }
        };
    }
}
