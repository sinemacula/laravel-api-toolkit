<?php

declare(strict_types = 1);

namespace Tests\Unit\Search;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Tests for the SearchDriverRegistry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SearchDriverRegistry::class)]
final class SearchDriverRegistryTest extends TestCase
{
    /** @var string The connection driver name used throughout */
    private const string CONNECTION = 'mysql';

    /** @var \SineMacula\ApiToolkit\Search\SearchDriverRegistry */
    private SearchDriverRegistry $registry;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new SearchDriverRegistry;
    }

    /**
     * Test that a registered driver resolves for its connection name.
     *
     * @return void
     */
    public function testRegisterStoresDriverForConnection(): void
    {
        $driver = $this->createStubDriver();

        $this->registry->register(self::CONNECTION, $driver);

        self::assertTrue($this->registry->has(self::CONNECTION));
        self::assertSame($driver, $this->registry->resolve(self::CONNECTION));
    }

    /**
     * Test that registering a connection twice is refused, carrying the whole
     * message that names the replacement route.
     *
     * @return void
     */
    public function testRegisterRefusesADuplicateConnection(): void
    {
        $this->registry->register(self::CONNECTION, $this->createStubDriver());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Search driver "mysql" is already registered. Use override() to replace it.');

        $this->registry->register(self::CONNECTION, $this->createStubDriver());
    }

    /**
     * Test that override replaces an existing driver.
     *
     * @return void
     */
    public function testOverrideReplacesAnExistingDriver(): void
    {
        $replacement = $this->createStubDriver();

        $this->registry->register(self::CONNECTION, $this->createStubDriver());
        $this->registry->override(self::CONNECTION, $replacement);

        self::assertSame($replacement, $this->registry->resolve(self::CONNECTION));
    }

    /**
     * Test that override registers when the connection is not yet present.
     *
     * @return void
     */
    public function testOverrideRegistersAnAbsentConnection(): void
    {
        $driver = $this->createStubDriver();

        $this->registry->override(self::CONNECTION, $driver);

        self::assertSame($driver, $this->registry->resolve(self::CONNECTION));
    }

    /**
     * Test that resolving an unregistered connection throws rather than
     * returning a null the caller could read as search being unavailable, which
     * would drop the predicate and widen the result set.
     *
     * @return void
     */
    public function testResolveThrowsForAnUnregisteredConnection(): void
    {
        $this->expectException(MissingSearchDriverException::class);
        $this->expectExceptionMessage('No search driver is registered for the "sqlite" connection. Register one to serve a search on that connection.');

        $this->registry->resolve('sqlite');
    }

    /**
     * Test that an unregistered connection is reported as absent.
     *
     * @return void
     */
    public function testHasReportsAnUnregisteredConnectionAsAbsent(): void
    {
        self::assertFalse($this->registry->has('sqlite'));
    }

    /**
     * Test that the registry reports every registered connection name.
     *
     * @return void
     */
    public function testReportsEveryRegisteredConnection(): void
    {
        self::assertSame([], $this->registry->connections());

        $this->registry->register(self::CONNECTION, $this->createStubDriver());
        $this->registry->register('pgsql', $this->createStubDriver());

        self::assertSame(['mysql', 'pgsql'], $this->registry->connections());
    }

    /**
     * Create a stub SearchDriver implementation for testing.
     *
     * @return \SineMacula\ApiToolkit\Contracts\SearchDriver
     */
    private function createStubDriver(): SearchDriver
    {
        /**
         * Stub SearchDriver for testing.
         *
         * @author      Ben Carey <bdmc@sinemacula.co.uk>
         * @copyright   2026 Sine Macula Limited.
         */
        return new class implements SearchDriver {
            /**
             * Return the match strategies this driver implements.
             *
             * @return array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>
             */
            #[\Override]
            public function supportedStrategies(): array
            {
                return [SearchStrategy::EXACT];
            }

            /**
             * Determine whether index backing can be proven on the connection.
             *
             * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
             * @param  \Illuminate\Database\Connection  $connection
             * @return bool
             */
            #[\Override]
            public function canVerifyIndexBacking(SearchStrategy $strategy, Connection $connection): bool
            {
                return false;
            }

            /**
             * Apply the search predicate for the given columns to the query.
             *
             * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
             * @param  array<int, string>  $columns
             * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
             * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
             * @return void
             */
            #[\Override]
            public function apply(Builder $query, array $columns, SearchTerm $term, SearchStrategy $strategy): void {}
        };
    }
}
