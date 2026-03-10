<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Encapsulates all interactions with the Laravel cache contract
 * for managing cached repository data and metadata.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CacheStore
{
    /** @var \Illuminate\Contracts\Cache\Repository The underlying cache store instance. */
    private readonly CacheContract $store;

    /** @var string The resolved cache key for the repository data. */
    private readonly string $cacheKey;

    /** @var string The resolved cache key for the repository metadata. */
    private readonly string $metaKey;

    /** @var string The Laravel cache store name. */
    private readonly string $cacheStore;

    /** @var string The prefix used for cache key generation. */
    private readonly string $cacheKeyPrefix;

    /** @var int The cache duration in seconds. */
    private readonly int $ttl;

    /**
     * Create a new cache store instance.
     *
     * @param  string  $cacheStore
     * @param  string  $cacheKeyPrefix
     * @param  int  $ttl
     * @return void
     */
    public function __construct(string $cacheStore, string $cacheKeyPrefix, int $ttl)
    {
        $this->cacheStore     = $cacheStore;
        $this->cacheKeyPrefix = $cacheKeyPrefix;
        $this->ttl            = $ttl;

        $this->store    = Cache::store($this->cacheStore);
        $this->cacheKey = CacheKeys::REPOSITORY_CACHE->resolveKey([$this->cacheKeyPrefix]);
        $this->metaKey  = CacheKeys::REPOSITORY_CACHE_META->resolveKey([$this->cacheKeyPrefix]);
    }

    /**
     * Get the cached collection, or null on a cache miss.
     *
     * @return \Illuminate\Support\Collection<int, mixed>|null
     */
    public function get(): ?Collection
    {
        /** @var \Illuminate\Support\Collection<int, mixed>|null */
        return $this->store->get($this->cacheKey);
    }

    /**
     * Store the given collection in the cache and record metadata.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $items
     * @return void
     */
    public function put(Collection $items): void
    {
        $this->store->put($this->cacheKey, $items, $this->ttl);
        $this->store->put($this->metaKey, ['populated_at' => now()->timestamp], $this->ttl);
    }

    /**
     * Remove the cached data and record an invalidation timestamp.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->store->forget($this->cacheKey);
        $this->store->put($this->metaKey, ['invalidated_at' => now()->timestamp], $this->ttl);
    }

    /**
     * Get the current cache status.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\CacheStatus
     */
    public function getStatus(): CacheStatus
    {
        /** @var array{populated_at?: int, invalidated_at?: int}|null $meta */
        $meta      = $this->store->get($this->metaKey);
        $populated = $this->store->has($this->cacheKey);

        $age = ($populated && isset($meta['populated_at']))
            ? (int) now()->timestamp - $meta['populated_at']
            : null;

        $lastInvalidatedAt = isset($meta['invalidated_at'])
            ? CarbonImmutable::createFromTimestamp($meta['invalidated_at'])
            : null;

        return new CacheStatus($populated, $age, $lastInvalidatedAt);
    }

    /**
     * Get the underlying cache repository instance.
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    public function getStore(): CacheContract
    {
        return $this->store;
    }
}
