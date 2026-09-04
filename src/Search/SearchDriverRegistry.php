<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search;

use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException;

/**
 * Registry for connection-driver-to-search-driver mappings.
 *
 * Stores and resolves search drivers keyed by the name the database connection
 * reports, so a resource declares what a search means and the connection in
 * front of it decides how that is served. Registration, override, and
 * resolution mirror the operator registry.
 *
 * Resolution of an unregistered name throws. Returning null would leave the
 * caller to decide what an absent driver means, and the tempting decision -
 * skip the search - drops the narrowing predicate and widens the result set,
 * which is the failure this layer exists to remove.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SearchDriverRegistry
{
    /** @var array<string, \SineMacula\ApiToolkit\Contracts\SearchDriver> */
    private array $drivers = [];

    /**
     * Register a search driver for the given connection driver name.
     *
     * Throws if the name is already registered. Use override() to replace an
     * existing driver.
     *
     * @param  string  $connection
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function register(string $connection, SearchDriver $driver): void
    {
        if (array_key_exists($connection, $this->drivers)) {
            throw new \InvalidArgumentException("Search driver \"{$connection}\" is already registered. Use override() to replace it.");
        }

        $this->drivers[$connection] = $driver;
    }

    /**
     * Replace the search driver for the given connection driver name
     * unconditionally.
     *
     * If the name is not currently registered, this behaves identically to
     * register().
     *
     * @param  string  $connection
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @return void
     */
    public function override(string $connection, SearchDriver $driver): void
    {
        $this->drivers[$connection] = $driver;
    }

    /**
     * Resolve the search driver for the given connection driver name.
     *
     * @param  string  $connection
     * @return \SineMacula\ApiToolkit\Contracts\SearchDriver
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException
     */
    public function resolve(string $connection): SearchDriver
    {
        return $this->drivers[$connection]
            ?? throw new MissingSearchDriverException("No search driver is registered for the \"{$connection}\" connection. Register one to serve a search on that connection.");
    }

    /**
     * Determine whether a search driver is registered for the given connection
     * driver name.
     *
     * @param  string  $connection
     * @return bool
     */
    public function has(string $connection): bool
    {
        return array_key_exists($connection, $this->drivers);
    }

    /**
     * Return every registered connection driver name.
     *
     * @return array<int, string>
     */
    public function connections(): array
    {
        return array_keys($this->drivers);
    }
}
