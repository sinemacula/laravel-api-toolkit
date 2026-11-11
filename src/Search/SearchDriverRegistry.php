<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search;

use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Exceptions\MissingSearchDriverException;

/**
 * Registry for connection-driver-to-search-driver mappings.
 *
 * An unregistered name throws: skipping the search would widen the result set.
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
     * Replace the search driver for the given connection driver name.
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
     * Determine whether a driver is registered for the given driver name.
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
