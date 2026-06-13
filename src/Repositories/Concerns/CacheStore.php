<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Cache\Repository as ConcreteRepository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Facades\Cache;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Encapsulates all interactions with the Laravel cache contract for managing
 * per-query repository cache entries and their invalidation.
 *
 * Each executed query is stored under a key derived from its fingerprint, so a
 * filtered or by-id read never returns the full-table collection. Invalidation
 * is performed per table — via cache tags when the store supports them, or via
 * a tracked key registry otherwise.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CacheStore
{
    /** @var \Illuminate\Contracts\Cache\Repository The underlying cache store instance. */
    private CacheContract $store;

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\CacheKeyRegistry|null The per-table live key registry, or null when disabled. */
    private ?CacheKeyRegistry $registry;

    /** @var string The cache tag scoping all per-query entries for the table. */
    private string $tag;

    /** @var string The resolved cache key for the repository metadata. */
    private string $metaKey;

    /** @var \Illuminate\Cache\Repository|null The concrete store when it supports tags, otherwise null. */
    private ?ConcreteRepository $taggableStore;

    /**
     * Create a new cache store instance.
     *
     * @param  string  $cacheStore
     * @param  string  $table
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\CacheStoreOptions  $options
     * @return void
     */
    public function __construct(

        /** The Laravel cache store name. */
        private string $cacheStore,

        /** The repository table used to namespace cache keys. */
        private string $table,

        /** The lifetime, size guard, and registry behaviour for the store. */
        private CacheStoreOptions $options,

    ) {
        $store = Cache::store($this->cacheStore);

        $this->store         = $store;
        $this->taggableStore = $store instanceof ConcreteRepository && $store->supportsTags() ? $store : null;
        $this->tag           = 'repo-table:' . $this->table;
        $this->metaKey       = CacheKeys::REPOSITORY_CACHE_META->resolveKey([$this->table]);
        $this->registry      = $this->options->registryEnabled
            ? new CacheKeyRegistry($this->store, $this->table, $this->options->ttl)
            : null;
    }

    /**
     * Get the cached result for the given query fingerprint, or null on a miss.
     *
     * @param  string  $hash
     * @return mixed
     */
    public function get(string $hash): mixed
    {
        return $this->scopedStore()->get($this->keyFor($hash));
    }

    /**
     * Determine whether a cached entry exists for the given fingerprint.
     *
     * @param  string  $hash
     * @return bool
     */
    public function has(string $hash): bool
    {
        return $this->scopedStore()->has($this->keyFor($hash));
    }

    /**
     * Store the given result for a query fingerprint, subject to the size guard.
     *
     * @param  string  $hash
     * @param  mixed  $result
     * @param  int  $rows
     * @return void
     */
    public function put(string $hash, mixed $result, int $rows): void
    {
        if (!$this->options->sizeGuard->allows($result, $rows)) {
            return;
        }

        $key = $this->keyFor($hash);

        $this->scopedStore()->put($key, $result, $this->options->ttl);
        $this->store->put($this->metaKey, ['populated_at' => now()->timestamp], $this->options->ttl);

        $this->registry?->track($key);
    }

    /**
     * Invalidate every per-query entry for the repository table.
     *
     * @return void
     */
    public function flushTable(): void
    {
        if ($this->taggableStore !== null) {
            $this->taggableStore->tags([$this->tag])->flush();
        } else {
            $this->registry?->flush();
        }

        $this->store->put($this->metaKey, ['invalidated_at' => now()->timestamp], $this->options->ttl);
    }

    /**
     * Get the current cache status.
     *
     * Note: the returned status reflects stored metadata, not a guaranteed data
     * presence. An external or shared-store flush can remove data without going
     * through flushTable(), leaving isPopulated() returning true while the
     * underlying entries are gone.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\CacheStatus
     */
    public function getStatus(): CacheStatus
    {
        /** @var array{populated_at?: int, invalidated_at?: int}|null $meta */
        $meta      = $this->store->get($this->metaKey);
        $populated = isset($meta['populated_at']) && !isset($meta['invalidated_at']);

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
     * Resolve the cache key for a query fingerprint.
     *
     * @param  string  $hash
     * @return string
     */
    private function keyFor(string $hash): string
    {
        return CacheKeys::REPOSITORY_QUERY_CACHE->resolveKey([$this->table, $hash]);
    }

    /**
     * Get the cache repository scoped to the table tag where supported.
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    private function scopedStore(): CacheContract
    {
        return $this->taggableStore !== null
            ? $this->taggableStore->tags([$this->tag])
            : $this->store;
    }
}
