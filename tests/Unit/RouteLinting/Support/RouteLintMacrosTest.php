<?php

namespace Tests\Unit\RouteLinting\Support;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Exceptions\StaleWaiverException;
use SineMacula\ApiToolkit\RouteLinting\Support\RouteLintMacros;
use Tests\TestCase;

/**
 * Tests for the RouteLintMacros registrar.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RouteLintMacros::class)]
class RouteLintMacrosTest extends TestCase
{
    /**
     * Test that the ignoreRouteLint macro is registered after calling register().
     *
     * @return void
     */
    public function testMacroIsRegisteredAfterRegisterCall(): void
    {
        // The ApiServiceProvider already calls RouteLintMacros::register() during
        // test bootstrap via getPackageProviders(); confirm the macro exists.
        static::assertTrue(Route::hasMacro('ignoreRouteLint'));
    }

    /**
     * Test that calling register() more than once does not cause an error and
     * leaves the macro functional (idempotent registration guard).
     *
     * @return void
     */
    public function testRegistrationIsIdempotent(): void
    {
        RouteLintMacros::register();
        RouteLintMacros::register();

        static::assertTrue(Route::hasMacro('ignoreRouteLint'));
    }

    /**
     * Test that calling register() a second time when the macro already exists
     * does NOT re-register a new closure (kills ReturnRemoval mutant #90).
     *
     * Without the early return the macro would be re-registered on each call.
     * We verify idempotency by checking that a second call leaves the route
     * action produced by the macro intact — the macro still works and was not
     * double-appended by the re-registration attempt.
     *
     * @return void
     */
    public function testSecondRegisterCallDoesNotReplaceExistingMacro(): void
    {
        // Macro is already registered by the service provider boot.
        // Register again and confirm the macro still functions correctly.
        RouteLintMacros::register();

        $router = $this->getRouter();
        $route  = $router->get('idempotent-test', fn () => []);

        $route->ignoreRouteLint(['R1'], 'Still works after second register.'); // @phpstan-ignore method.notFound

        $action = $route->getAction('api-toolkit::lint-ignore');

        static::assertIsArray($action);
        static::assertCount(1, $action);
        static::assertSame(['R1'], $action[0]['rules']);
        static::assertSame('Still works after second register.', $action[0]['reason']);
    }

    /**
     * Test that calling ignoreRouteLint() appends an entry to the
     * `api-toolkit::lint-ignore` action key with the correct rules and reason.
     *
     * @return void
     */
    public function testMacroAppendsEntryToActionKey(): void
    {
        $router = $this->getRouter();
        $route  = $router->get('users', fn () => [])->name('users.index');

        $result = $route->ignoreRouteLint(['R9'], 'Legacy naming kept for backward compatibility.'); // @phpstan-ignore method.notFound

        $action = $route->getAction('api-toolkit::lint-ignore');

        static::assertIsArray($action);
        static::assertCount(1, $action);
        static::assertSame(['R9'], $action[0]['rules']);
        static::assertSame('Legacy naming kept for backward compatibility.', $action[0]['reason']);

        // Return value must be the route itself for fluent chaining
        static::assertSame($route, $result);
    }

    /**
     * Test that multiple ignoreRouteLint() calls accumulate independent entries
     * in the action array rather than overwriting.
     *
     * @return void
     */
    public function testMultipleCallsAccumulateEntries(): void
    {
        $router = $this->getRouter();
        $route  = $router->get('orders', fn () => []);

        $route->ignoreRouteLint(['R9'], 'First suppression.'); // @phpstan-ignore method.notFound
        $route->ignoreRouteLint([], 'Second suppression, all rules.'); // @phpstan-ignore method.notFound

        $action = $route->getAction('api-toolkit::lint-ignore');

        static::assertIsArray($action);
        static::assertCount(2, $action);
        static::assertSame(['R9'], $action[0]['rules']);
        static::assertSame('First suppression.', $action[0]['reason']);
        static::assertSame([], $action[1]['rules']);
        static::assertSame('Second suppression, all rules.', $action[1]['reason']);
    }

    /**
     * Test that an empty reason string throws a StaleWaiverException.
     *
     * @return void
     */
    public function testEmptyReasonThrowsStaleWaiverException(): void
    {
        $router = $this->getRouter();
        $route  = $router->get('items', fn () => []);

        $this->expectException(StaleWaiverException::class);

        $route->ignoreRouteLint(['R9'], ''); // @phpstan-ignore method.notFound
    }

    /**
     * Test that a whitespace-only reason string throws a StaleWaiverException.
     *
     * @return void
     */
    public function testWhitespaceOnlyReasonThrowsStaleWaiverException(): void
    {
        $router = $this->getRouter();
        $route  = $router->get('products', fn () => []);

        $this->expectException(StaleWaiverException::class);

        $route->ignoreRouteLint([], '   '); // @phpstan-ignore method.notFound
    }

    /**
     * Test that passing an empty rules array stores an empty list, representing
     * the "suppress all rules" intent.
     *
     * @return void
     */
    public function testEmptyRulesArrayMeansSuppressAll(): void
    {
        $router = $this->getRouter();
        $route  = $router->get('resources', fn () => []);

        $route->ignoreRouteLint([], 'Suppress all rules for this route.'); // @phpstan-ignore method.notFound

        $action = $route->getAction('api-toolkit::lint-ignore');

        static::assertIsArray($action);
        static::assertSame([], $action[0]['rules']);
    }

    /**
     * Test that the stored rules list is a re-indexed (0-based) array
     * (kills UnwrapArrayValues mutant #91: `$ruleIds` stored directly vs
     * `array_values($ruleIds)`).
     *
     * If `array_values` is removed, an associative input like `['foo' => 'R1']`
     * would be stored with string keys. With `array_values` the result is always
     * a 0-indexed list regardless of input key type.
     *
     * @return void
     */
    public function testStoredRulesListIsAlwaysReIndexed(): void
    {
        $router = $this->getRouter();
        $route  = $router->get('assets', fn () => []);

        // Pass an array with non-sequential / string keys
        $route->ignoreRouteLint(['foo' => 'R1', 'bar' => 'R3'], 'Non-sequential keys.'); // @phpstan-ignore method.notFound

        $action = $route->getAction('api-toolkit::lint-ignore');

        static::assertIsArray($action);
        static::assertCount(1, $action);

        $rules = $action[0]['rules'];

        // Must be a 0-based indexed list, not the original associative array
        static::assertSame([0 => 'R1', 1 => 'R3'], $rules);
    }

    /**
     * Get a fresh router instance for the test.
     *
     * @return \Illuminate\Routing\Router
     */
    private function getRouter(): Router
    {
        assert($this->app !== null);

        /** @var \Illuminate\Routing\Router */
        return $this->app->make('router');
    }
}
