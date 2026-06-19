<?php

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * Provides opt-in transparent caching for API repositories.
 *
 * When used by an ApiRepository subclass, this trait intercepts read
 * operations before execution and serves matching results from a per-query
 * cache, guaranteeing zero database queries on a cache hit. Each executed
 * query is keyed by its fingerprint, so a filtered or by-id read never returns
 * the full-table collection. Write operations invalidate the whole table.
 *
 * Overridable configuration properties (declare in the consuming class to
 * change defaults):
 *
 *   - `protected int $cacheTtl = 3600` — cache duration in seconds
 *   - `protected ?string $cacheStoreName = null` — Laravel cache store
 *   - `protected ?string $cacheKeyPrefix = null` — cache key prefix
 *   - `protected ?int $cacheMaxRows` — size guard row ceiling
 *   - `protected ?int $cacheMaxBytes` — size guard byte ceiling
 *   - `protected int $cacheReferenceTtl` — reference-mode cache duration
 *   - `protected bool $cacheReferenceTable = true` — opt into whole-table mode
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait Cacheable
{
    /** @var array<int, string> Read verbs whose results are served from the per-query cache. */
    private const array CACHEABLE_READS = ['get', 'all', 'find', 'first', 'firstWhere', 'firstOrFail', 'findOrFail', 'sole', 'value', 'pluck'];

    /** @var array<int, string> Write verbs that invalidate the whole-table cache after execution. */
    private const array WRITE_VERBS = [
        'create', 'forceCreate', 'firstOrCreate', 'updateOrCreate', 'updateOrInsert',
        'update', 'delete', 'forceDelete', 'save', 'insert', 'insertGetId',
        'upsert', 'increment', 'decrement', 'restore',
    ];

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\CacheStore The per-query cache store collaborator. */
    private CacheStore $cacheStore;

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\ReferenceCache The whole-table reference cache collaborator. */
    private ReferenceCache $referenceCache;

    /** @var bool Whether the repository operates in whole-table reference mode. */
    private bool $cacheReferenceMode = false;

    /** @var bool Transient flag for bypassing the cache on the next read. */
    private bool $bypassCache = false;

    /**
     * Forward method calls to the model, applying cache interception.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[\Override]
    public function __call(string $method, array $arguments): mixed
    {
        if (in_array($method, self::WRITE_VERBS, true)) {
            return $this->forwardAndFlush($method, $arguments);
        }

        return $this->resolveRead($method, $arguments);
    }

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
        $this->activeStore()->flushTable();
    }

    /**
     * Get the current cache status.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\CacheStatus
     */
    public function getCacheStatus(): CacheStatus
    {
        return $this->cacheReferenceMode
            ? $this->referenceCache->getStatus()
            : $this->cacheStore->getStatus();
    }

    /**
     * Boot the cacheable concern.
     *
     * Invoked by ApiRepository::bootConcerns() rather than overriding boot()
     * directly, so the concern can coexist with other bootable concerns (e.g.
     * Deferrable) without a fatal trait collision.
     *
     * @return void
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    protected function bootCacheable(): void
    {
        $table = $this->getModel()->getTable();
        $store = $this->resolveProperty('cacheStoreName') ?? Config::get('api-toolkit.repositories.cache.store') ?? Config::get('cache.default');
        $store = is_string($store) ? $store : 'array';

        $prefix = $this->resolveProperty('cacheKeyPrefix') ?? $table;
        $prefix = is_string($prefix) ? $prefix : $table;

        $this->cacheReferenceMode = (bool) ($this->resolveProperty('cacheReferenceTable') ?? false);

        $this->cacheStore     = new CacheStore($store, $prefix, $this->resolveStoreOptions());
        $this->referenceCache = new ReferenceCache($store, $prefix, $this->resolveReferenceTtl());
    }

    /**
     * Resolve a read verb through the appropriate cache strategy.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function resolveRead(string $method, array $arguments): mixed
    {
        if ($this->shouldBypass()) {
            return parent::__call($method, $arguments);
        }

        if ($this->cacheReferenceMode) {
            return $this->isReferenceRead($method)
                ? $this->resolveReferenceRead($method, $arguments)
                : parent::__call($method, $arguments);
        }

        return in_array($method, self::CACHEABLE_READS, true)
            ? $this->resolveCachedRead($method, $arguments)
            : parent::__call($method, $arguments);
    }

    /**
     * Execute a write verb through the parent pipeline then flush the cache.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function forwardAndFlush(string $method, array $arguments): mixed
    {
        $result = parent::__call($method, $arguments);

        $this->activeStore()->flushTable();

        return $result;
    }

    /**
     * Resolve a cacheable read via pre-execution interception.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function resolveCachedRead(string $method, array $arguments): mixed
    {
        $query = $this->prepareQueryBuilder();
        $hash  = QueryFingerprint::for($query, $method, $arguments);

        if ($this->cacheStore->has($hash)) {
            return parent::resetAndReturn($this->cacheStore->get($hash));
        }

        $result = \Closure::fromCallable([$query, $method])(...$arguments);

        if ($result !== null) {
            $this->cacheStore->put($hash, $result, $this->rowCount($result));
        }

        return parent::resetAndReturn($result);
    }

    /**
     * Resolve a read from the whole-table reference cache.
     *
     * @param  string  $method
     * @param  array<int, mixed>  $arguments
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    private function resolveReferenceRead(string $method, array $arguments): mixed
    {
        $model = $this->getModel();

        $result = $method === 'find'
            ? $this->referenceCache->find($model, $this->referenceId($arguments))
            : $this->referenceCache->all($model);

        return parent::resetAndReturn($result);
    }

    /**
     * Determine whether the given verb can be served from the reference cache.
     *
     * @param  string  $method
     * @return bool
     */
    private function isReferenceRead(string $method): bool
    {
        return in_array($method, ['get', 'all', 'find'], true);
    }

    /**
     * Resolve the primary key argument for a reference-mode find().
     *
     * @param  array<int, mixed>  $arguments
     * @return int|string
     */
    private function referenceId(array $arguments): int|string
    {
        $id = $arguments[0] ?? 0;

        return is_int($id) || is_string($id) ? $id : (string) $id; // @phpstan-ignore cast.string
    }

    /**
     * Count the rows represented by a query result for the size guard.
     *
     * @param  mixed  $result
     * @return int
     */
    private function rowCount(mixed $result): int
    {
        if ($result instanceof Collection) {
            return $result->count();
        }

        return $result instanceof Model ? 1 : 0;
    }

    /**
     * Determine whether the next read should bypass the cache, consuming the
     * transient flag.
     *
     * @return bool
     */
    private function shouldBypass(): bool
    {
        if ($this->bypassCache) {

            $this->bypassCache = false;

            return true;
        }

        return false;
    }

    /**
     * Resolve the active cache store for invalidation.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\CacheStore|\SineMacula\ApiToolkit\Repositories\Concerns\ReferenceCache
     */
    private function activeStore(): CacheStore|ReferenceCache
    {
        return $this->cacheReferenceMode ? $this->referenceCache : $this->cacheStore;
    }

    /**
     * Resolve the configured cache TTL.
     *
     * @return int
     */
    private function resolveTtl(): int
    {
        $ttl = $this->resolveProperty('cacheTtl') ?? Config::get('api-toolkit.repositories.cache.ttl', 3600);

        return is_numeric($ttl) ? (int) $ttl : 3600;
    }

    /**
     * Resolve the configured reference-mode cache TTL.
     *
     * @return int
     */
    private function resolveReferenceTtl(): int
    {
        $ttl = $this->resolveProperty('cacheReferenceTtl') ?? Config::get('api-toolkit.repositories.cache.reference_ttl', 3600);

        return is_numeric($ttl) ? (int) $ttl : 3600;
    }

    /**
     * Resolve the per-query cache store options from properties and config.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\CacheStoreOptions
     */
    private function resolveStoreOptions(): CacheStoreOptions
    {
        $registryEnabled = $this->resolveProperty('cacheRegistryEnabled')
            ?? Config::get('api-toolkit.repositories.cache.registry_enabled', true);

        return new CacheStoreOptions($this->resolveTtl(), $this->resolveSizeGuard(), (bool) $registryEnabled);
    }

    /**
     * Build the size guard from the configured row and byte ceilings.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\CacheSizeGuard
     */
    private function resolveSizeGuard(): CacheSizeGuard
    {
        $maxRows  = $this->resolveProperty('cacheMaxRows')  ?? Config::get('api-toolkit.repositories.cache.max_rows', 1000);
        $maxBytes = $this->resolveProperty('cacheMaxBytes') ?? Config::get('api-toolkit.repositories.cache.max_bytes', 262144);

        return new CacheSizeGuard(
            is_numeric($maxRows) ? (int) $maxRows : null,
            is_numeric($maxBytes) ? (int) $maxBytes : null,
        );
    }

    /**
     * Resolve an overridable property declared on the consuming repository.
     *
     * @param  string  $name
     * @return mixed
     */
    private function resolveProperty(string $name): mixed
    {
        return property_exists($this, $name) ? $this->{$name} : null;
    }
}
