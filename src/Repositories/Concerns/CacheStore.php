<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Cache\Repository as ConcreteRepository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Facades\Cache;
use SineMacula\ApiToolkit\Contracts\CacheInvalidator;
use SineMacula\ApiToolkit\Enums\CacheKeys;

/**
 * Encapsulates all interactions with the Laravel cache contract for managing
 * per-query repository cache entries and their invalidation.
 *
 * Each executed query is stored under a key derived from its fingerprint, so a
 * filtered or by-id read never returns the full-table collection. Invalidation
 * is performed per table - via cache tags when the store supports them, or via
 * a generational table version otherwise: every per-query key embeds the
 * current table version, and a write bumps the version with a single atomic
 * increment. Invalidation is therefore O(1) and free of the read-modify-write
 * races a tracked key set suffers; orphaned old-version entries simply expire
 * by TTL.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CacheStore implements CacheInvalidator
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

        /** The name of the configured cache store to use. */
        private readonly string $cacheStore,

        /** The table whose per-query entries are managed. */
        private readonly string $table,

        /** The cache behaviour options for this store. */
        private readonly CacheStoreOptions $options,
    ) {
        $store = Cache::store($this->cacheStore);

        $this->store         = $store;
        $this->taggableStore = $store instanceof ConcreteRepository && $store->supportsTags() ? $store : null;
        $this->tag           = self::tagFor($this->table);
        $this->metaKey       = CacheKeys::REPOSITORY_CACHE_META->resolveKey([$this->table]);
        $this->versionKey    = self::versionKeyFor($this->table);
    }

    /**
     * Invalidate every per-query cache entry for a table without a configured
     * store instance, for cross-cutting callers (such as the deferred-write
     * boundary flush) that only need to drop a table's cache.
     *
     * This is the invalidation half of flushTable(): it flushes the table tag
     * on a taggable store, or bumps the generational version on a non-taggable
     * store when the registry is enabled. It deliberately omits the metadata
     * write flushTable() performs, because the caller is not the owning
     * repository and does not track that table's cache status.
     *
     * @param  string  $cacheStore
     * @param  string  $table
     * @param  bool  $registryEnabled
     * @return void
     */
    public static function invalidateTable(string $cacheStore, string $table, bool $registryEnabled): void
    {
        $store = Cache::store($cacheStore);

        if ($store instanceof ConcreteRepository && $store->supportsTags()) {
            $store->tags([self::tagFor($table)])->flush();

            return;
        }

        if (!$registryEnabled) {
            return;
        }

        $store->increment(self::versionKeyFor($table));
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
     * Store the given result for a query fingerprint, subject to the size
     * guard.
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
    #[\Override]
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
     * CONSISTENCY CONTRACT - NON-TAGGABLE STORES (file, database, etc.)
     *
     * The version is read from the cache once per instance and then held in
     * $this->version for the remainder of the request. This is intentional
     * request-scoped snapshotting: within a single request all cache-key
     * lookups embed the same version, so reads are coherent and the per-query
     * keys remain stable without repeated round-trips to the backing store.
     *
     * The trade-off is eventual consistency across processes. If a different
     * request (or queue worker) bumps the version via a write while this
     * instance is alive, the bump is invisible here until this instance is
     * destroyed (typically at end-of-request). During that window this instance
     * may serve cache entries that have been logically invalidated by the other
     * process. Because the orphaned entries expire naturally by their TTL, the
     * inconsistency is bounded and self-healing.
     *
     * Taggable stores (e.g. Redis with tags enabled) are not affected by this
     * contract - they skip the generational-version path entirely and rely on
     * the tag-invalidation mechanism instead.
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

    /**
     * Resolve the cache tag scoping all per-query entries for a table.
     *
     * @param  string  $table
     * @return string
     */
    private static function tagFor(string $table): string
    {
        return 'repo-table:' . $table;
    }

    /**
     * Resolve the cache key holding a table's generational version.
     *
     * @param  string  $table
     * @return string
     */
    private static function versionKeyFor(string $table): string
    {
        return CacheKeys::REPOSITORY_CACHE_VERSION->resolveKey([$table]);
    }
}
