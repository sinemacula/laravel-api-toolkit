<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Support\Facades\Config;

/**
 * Provides opt-in transparent caching for API repositories.
 *
 * When used by an ApiRepository subclass, this trait intercepts
 * read operations to serve cached data and automatically invalidates
 * the cache on write operations.
 *
 * Overridable configuration properties (declare in the consuming class
 * to change defaults):
 *
 *   - `protected int $cacheTtl = 3600` — cache duration in seconds
 *   - `protected ?string $cacheStoreName = null` — Laravel cache store
 *   - `protected ?string $cacheKeyPrefix = null` — cache key prefix
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait Cacheable
{
    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\CacheStore The cache store collaborator instance. */
    private CacheStore $cacheStore;

    /** @var bool Transient flag for bypassing cache on the next read. */
    private bool $bypassCache = false;

    /**
     * Bypass the cache for the next read operation.
     *
     * @return static
     */
    public function withoutCache(): static
    {
        $this->bypassCache = true;

        return $this;
    }

    /**
     * Flush the repository cache.
     *
     * @return void
     */
    public function flushCache(): void
    {
        $this->cacheStore->flush();
    }

    /**
     * Get the current cache status.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\CacheStatus
     */
    public function getCacheStatus(): CacheStatus
    {
        return $this->cacheStore->getStatus();
    }

    /**
     * Boot the repository instance.
     *
     * @return void
     */
    #[\Override]
    protected function boot(): void
    {
        parent::boot();

        $ttl       = property_exists($this, 'cacheTtl') ? $this->cacheTtl : 3600;
        $storeName = property_exists($this, 'cacheStoreName') ? $this->cacheStoreName : null;
        $keyPrefix = property_exists($this, 'cacheKeyPrefix') ? $this->cacheKeyPrefix : null;

        $storeName ??= Config::get('cache.default');
        $keyPrefix ??= $this->getModel()->getTable();

        $this->cacheStore = new CacheStore($storeName, $keyPrefix, $ttl);
    }

    /**
     * Reset the various transient values and return the result.
     *
     * @param  mixed  $queryResult
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[\Override]
    protected function resetAndReturn(mixed $queryResult): mixed
    {
        $result = parent::resetAndReturn($queryResult);

        if (is_bool($result) || is_int($result)) {

            $this->cacheStore->flush();

            return $result;
        }

        if ($this->bypassCache) {

            $this->bypassCache = false;

            return $result;
        }

        return $this->resolveCachedResult($result);
    }

    /**
     * Resolve the result from the cache, populating it on a miss.
     *
     * @param  mixed  $result
     * @return mixed
     */
    private function resolveCachedResult(mixed $result): mixed
    {
        $cached = $this->cacheStore->get();

        if ($cached !== null) {
            return $cached;
        }

        $allRows = $this->getModel()->newQuery()->get();

        $this->cacheStore->put($allRows);

        return $result;
    }
}
