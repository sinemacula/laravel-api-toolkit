<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Support\Facades\Config;
use SineMacula\Repositories\Concerns\CacheStore;

/**
 * Invalidates the per-query repository cache for tables persisted by a deferred
 * write-pool flush.
 *
 * A deferred insert is persisted at the lifecycle boundary, outside the
 * Cacheable read path, so the per-query cache for that table would otherwise
 * serve a stale collection until its TTL expired. This best-effort invalidator
 * bumps each flushed table's generational cache version (or flushes its tag)
 * using the default repository-cache configuration, mirroring what an immediate
 * write does. Repositories on a custom cache store or key prefix are not
 * covered and must invalidate manually.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DeferredWriteCacheInvalidator
{
    /**
     * Invalidate the per-query cache for each of the given tables.
     *
     * @param  array<int, string>  $tables
     * @return void
     */
    public function invalidate(array $tables): void
    {
        $store    = $this->resolveStore();
        $registry = (bool) Config::get('repositories.cache.registry_enabled', true);

        foreach ($tables as $table) {
            CacheStore::invalidateTable($store, $table, $registry);
        }
    }

    /**
     * Resolve the cache store name shared by default-config Cacheable
     * repositories.
     *
     * @return string
     */
    private function resolveStore(): string
    {
        $store = Config::get('repositories.cache.store') ?? Config::get('cache.default');

        return is_string($store) ? $store : 'array';
    }
}
