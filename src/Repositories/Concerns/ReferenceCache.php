<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Whole-table reference cache for repositories whose backing table is small,
 * static, and read in full.
 *
 * In reference mode the table is loaded once and served from memory: full
 * collection reads and single-record lookups by primary key resolve without
 * touching the database. This preserves the toolkit's historical whole-table
 * caching semantics, including cross-request persistence, as an explicit
 * opt-in.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ReferenceCache
{
    /** @var \Illuminate\Contracts\Cache\Repository The underlying cache store instance. */
    private CacheContract $store;

    /** @var string The resolved cache key for the whole-table snapshot. */
    private string $cacheKey;

    /** @var string The resolved cache key for the reference metadata. */
    private string $metaKey;

    /**
     * Create a new reference cache instance.
     *
     * @param  string  $cacheStore
     * @param  string  $table
     * @param  int  $ttl
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\CacheSizeGuard  $sizeGuard
     * @return void
     */
    public function __construct(
        private string $cacheStore,
        private string $table,
        private int $ttl,
        private CacheSizeGuard $sizeGuard,
    ) {
        $this->store    = Cache::store($this->cacheStore);
        $this->cacheKey = CacheKeys::REPOSITORY_CACHE->resolveKey([$this->table]);
        $this->metaKey  = CacheKeys::REPOSITORY_CACHE_META->resolveKey([$this->table]);
    }

    /**
     * Get the whole-table snapshot, loading it from the database on a miss.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function all(Model $model): Collection
    {
        $cached = $this->snapshot();

        if ($cached !== null) {
            return $cached;
        }

        /** @var \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model> $rows */
        $rows = $model->newQuery()->get();

        // Skip caching a table that exceeds the size guard, so reference mode on
        // an unexpectedly large table falls back to querying rather than holding
        // a huge serialized snapshot in the cache.
        if ($this->sizeGuard->allows($rows, $rows->count())) {
            $this->store->put($this->cacheKey, $rows, $this->ttl);
            $this->store->put($this->metaKey, ['populated_at' => now()->timestamp], $this->ttl);
        }

        return $rows;
    }

    /**
     * Find a single record by its key value from the whole-table snapshot.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  int|string  $id
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function find(Model $model, int|string $id): ?Model
    {
        return $this->all($model)->firstWhere($model->getKeyName(), $id);
    }

    /**
     * Invalidate the whole-table snapshot.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->store->forget($this->cacheKey);
        $this->store->put($this->metaKey, ['invalidated_at' => now()->timestamp], $this->ttl);
    }

    /**
     * Get the current reference cache status.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\CacheStatus
     */
    public function getStatus(): CacheStatus
    {
        /** @var array{populated_at?: int, invalidated_at?: int}|null $meta */
        $meta      = $this->store->get($this->metaKey);
        $populated = $this->store->has($this->cacheKey);

        return CacheStatus::fromMeta($meta, $populated);
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

    /**
     * Get the cached whole-table snapshot, or null on a miss.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>|null
     */
    private function snapshot(): ?Collection
    {
        /** @var \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>|null */
        return $this->store->get($this->cacheKey);
    }
}
