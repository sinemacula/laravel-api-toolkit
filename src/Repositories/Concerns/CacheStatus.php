<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Carbon\CarbonInterface;

/**
 * Immutable value object representing the current state of a
 * repository cache entry.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CacheStatus
{
    /**
     * Create a new cache status instance.
     *
     * @param  bool  $populated
     * @param  int|null  $age
     * @param  \Carbon\CarbonInterface|null  $lastInvalidatedAt
     * @return void
     */
    public function __construct(

        /** Whether the cache currently holds data. */
        private bool $populated,

        /** Seconds since the cache was last populated. */
        private ?int $age,

        /** When the cache was last invalidated. */
        private ?CarbonInterface $lastInvalidatedAt,

    ) {}

    /**
     * Determine whether the cache currently holds data.
     *
     * @return bool
     */
    public function isPopulated(): bool
    {
        return $this->populated;
    }

    /**
     * Get the age of the cache in seconds.
     *
     * @return int|null
     */
    public function getAge(): ?int
    {
        return $this->age;
    }

    /**
     * Get the timestamp of the last cache invalidation.
     *
     * @return \Carbon\CarbonInterface|null
     */
    public function getLastInvalidatedAt(): ?CarbonInterface
    {
        return $this->lastInvalidatedAt;
    }
}
