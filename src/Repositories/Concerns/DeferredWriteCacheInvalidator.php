<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

use Illuminate\Support\Facades\Config;
use SineMacula\Repositories\Concerns\CacheStore;

/**
 * Invalidates the per-query repository cache for tables a write flush touched.
 *
 * Repositories on a custom cache store or key prefix must invalidate manually.
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
     * Resolve the cache store name shared by default-config repositories.
     *
     * @return string
     */
    private function resolveStore(): string
    {
        $store = Config::get('repositories.cache.store') ?? Config::get('cache.default');

        return is_string($store) ? $store : 'array';
    }
}
