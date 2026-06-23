<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Carbon\CarbonImmutable;
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
        private bool $populated,
        private ?int $age,
        private ?CarbonInterface $lastInvalidatedAt,
    ) {}

    /**
     * Build a cache status from the stored metadata and population flag.
     *
     * @param  array{populated_at?: int, invalidated_at?: int}|null  $meta
     * @param  bool  $populated
     * @return self
     */
    public static function fromMeta(?array $meta, bool $populated): self
    {
        $age = ($populated && isset($meta['populated_at']))
            ? (int) now()->timestamp - $meta['populated_at']
            : null;

        $lastInvalidatedAt = isset($meta['invalidated_at'])
            ? CarbonImmutable::createFromTimestamp($meta['invalidated_at'])
            : null;

        return new self($populated, $age, $lastInvalidatedAt);
    }

    /**
     * Determine whether the cache currently holds data.
     *
     * Note: this reflects stored metadata, not a guaranteed data presence. An
     * external or shared-store flush can remove entries without going through
     * flushTable(), leaving this returning true while the underlying data is gone.
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
