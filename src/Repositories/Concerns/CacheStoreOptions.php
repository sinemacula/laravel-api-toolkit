<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Concerns;

/**
 * Immutable configuration bundle for a per-query repository cache store.
 *
 * Groups the correlated tuning parameters — lifetime, size guard, registry
 * behaviour, and negative-lookup lifetime — so the cache store constructor
 * stays within the parameter limit and the options can be resolved once at
 * boot.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CacheStoreOptions
{
    /**
     * Create a new cache store options instance.
     *
     * @param  int  $ttl
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\CacheSizeGuard  $sizeGuard
     * @param  bool  $registryEnabled
     * @param  int  $negativeTtl
     * @return void
     */
    public function __construct(
        public int $ttl,
        public CacheSizeGuard $sizeGuard,
        public bool $registryEnabled,
        public int $negativeTtl,
    ) {}
}
