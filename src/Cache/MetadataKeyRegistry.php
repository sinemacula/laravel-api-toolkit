<?php

namespace SineMacula\ApiToolkit\Cache;

/**
 * Tracks the live set of toolkit metadata cache keys so the cache manager can
 * forget exactly those keys on flush, leaving non-toolkit keys intact.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MetadataKeyRegistry
{
    /** @var array<string, string> The registered keys, mapped to themselves for O(1) deduplication. */
    private array $keys = [];

    /**
     * Register a metadata cache key.
     *
     * Registering a key that is already present is a no-op; the set remains
     * deduplicated.
     *
     * @param  string  $key
     * @return void
     */
    public function register(string $key): void
    {
        $this->keys[$key] = $key;
    }

    /**
     * Return the distinct registered keys as an integer-indexed list.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_values($this->keys);
    }

    /**
     * Empty the registry.
     *
     * After calling this method, {@see keys()} returns an empty array.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->keys = [];
    }
}
