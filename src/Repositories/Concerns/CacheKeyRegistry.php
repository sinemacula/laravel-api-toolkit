<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Contracts\Cache\Repository as CacheContract;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Tracks the live per-query cache keys for a repository table on cache stores
 * that do not support tagging.
 *
 * Each per-query key is recorded against a per-table registry set so that a
 * write operation can forget every live entry without relying on tag flushes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CacheKeyRegistry
{
    /** @var string The resolved registry key for the repository table. */
    private string $registryKey;

    /**
     * Create a new cache key registry instance.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $store
     * @param  string  $table
     * @param  int  $ttl
     * @return void
     */
    public function __construct(

        /** The underlying cache store the registry persists to. */
        private CacheContract $store,

        /** The repository table the tracked keys belong to. */
        private string $table,

        /** The lifetime, in seconds, of the registry entry. */
        private int $ttl,

    ) {
        $this->registryKey = CacheKeys::REPOSITORY_CACHE_REGISTRY->resolveKey([$this->table]);
    }

    /**
     * Record a live per-query cache key in the registry.
     *
     * @param  string  $key
     * @return void
     */
    public function track(string $key): void
    {
        $keys = $this->all();

        $keys[$key] = true;

        $this->store->put($this->registryKey, $keys, $this->ttl);
    }

    /**
     * Forget every tracked per-query key and clear the registry itself.
     *
     * @return void
     */
    public function flush(): void
    {
        foreach (array_keys($this->all()) as $key) {
            $this->store->forget($key);
        }

        $this->store->forget($this->registryKey);
    }

    /**
     * Get the currently tracked per-query keys.
     *
     * @return array<string, true>
     */
    private function all(): array
    {
        /** @var array<string, true>|null $keys */
        $keys = $this->store->get($this->registryKey);

        return is_array($keys) ? $keys : [];
    }
}
