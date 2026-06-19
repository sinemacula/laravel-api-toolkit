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
 * is performed per table - via cache tags when the store supports them, or via a
 * generational table version otherwise: every per-query key embeds the current
 * table version, and a write bumps the version with a single atomic increment.
 * Invalidation is therefore O(1) and free of the read-modify-write races a
 * tracked key set suffers; orphaned old-version entries simply expire by TTL.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CacheStore
{
    /** @var \Illuminate\Contracts\Cache\Repository The underlying cache store instance. */
    private readonly CacheContract $store;

    /** @var string The cache tag scoping all per-query entries for the table. */
    private readonly string $tag;

    /** @var string The resolved cache key for the repository metadata. */
    private readonly string $metaKey;

    /** @var string The resolved cache key holding the table's generational version. */
    private readonly string $versionKey;

    /** @var \Illuminate\Cache\Repository|null The concrete store when it supports tags, otherwise null. */
    private readonly ?ConcreteRepository $taggableStore;

    /** @var int|null The memoised table version for the non-taggable generational scheme. */
    private ?int $version = null;

    /**
     * Create a new cache store instance.
     *
     * @param  string  $cacheStore
     * @param  string  $table
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\CacheStoreOptions  $options
     * @return void
     */
    public function __construct(
        private readonly string $cacheStore,
        private readonly string $table,
        private readonly CacheStoreOptions $options,
    ) {
        $store = Cache::store($this->cacheStore);

        $this->store         = $store;
        $this->taggableStore = $store instanceof ConcreteRepository && $store->supportsTags() ? $store : null;
        $this->tag           = 'repo-table:' . $this->table;
        $this->metaKey       = CacheKeys::REPOSITORY_CACHE_META->resolveKey([$this->table]);
        $this->versionKey    = CacheKeys::REPOSITORY_CACHE_VERSION->resolveKey([$this->table]);
    }

    /**
     * Get the cached result for the given query fingerprint, or null on a miss.
     *
     * A negatively cached read is stored as a CacheMiss marker and translated
     * back to null here, so callers see a transparent null on a negative hit.
     *
     * @param  string  $hash
     * @return mixed
     */
    public function get(string $hash): mixed
    {
        $value = $this->scopedStore()->get($this->keyFor($hash));

        return $value instanceof CacheMiss ? null : $value;
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

        $this->scopedStore()->put($this->keyFor($hash), $result, $this->options->ttl);
        $this->store->put($this->metaKey, ['populated_at' => now()->timestamp], $this->options->ttl);
    }

    /**
     * Store a negative (null/miss) marker for a query fingerprint under the
     * shorter negative TTL.
     *
     * The marker is scoped to the table tag/version like any other entry, so a
     * write still invalidates it; it bypasses the size guard because it is a
     * constant-size sentinel, and it does not touch the populated_at metadata
     * because it represents the absence of data rather than cached data.
     *
     * @param  string  $hash
     * @return void
     */
    public function putMiss(string $hash): void
    {
        $this->scopedStore()->put($this->keyFor($hash), new CacheMiss, $this->options->negativeTtl);
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
        } elseif ($this->options->registryEnabled) {
            $this->bumpVersion();
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
     * On taggable stores the tag handles invalidation, so the key is the bare
     * table/fingerprint pair. Otherwise the current generational version is
     * folded in, so a version bump orphans every previously stored key.
     *
     * @param  string  $hash
     * @return string
     */
    private function keyFor(string $hash): string
    {
        $scopedHash = $this->taggableStore !== null ? $hash : $this->tableVersion() . ':' . $hash;

        return CacheKeys::REPOSITORY_QUERY_CACHE->resolveKey([$this->table, $scopedHash]);
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

    /**
     * Resolve the table's current generational version, memoised for the
     * lifetime of this store instance.
     *
     * @return int
     */
    private function tableVersion(): int
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $value = $this->store->get($this->versionKey);

        return $this->version = is_int($value) ? $value : 0;
    }

    /**
     * Bump the table's generational version, orphaning every existing per-query
     * key for the table in a single atomic write.
     *
     * @return void
     */
    private function bumpVersion(): void
    {
        $bumped = $this->store->increment($this->versionKey);

        $this->version = is_int($bumped) ? $bumped : $this->tableVersion() + 1;
    }
}
