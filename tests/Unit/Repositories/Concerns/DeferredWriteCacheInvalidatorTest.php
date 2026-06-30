<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheSizeGuard;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheStore;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheStoreOptions;
use SineMacula\ApiToolkit\Repositories\Concerns\DeferredWriteCacheInvalidator;
use Tests\TestCase;

/**
 * Tests for the DeferredWriteCacheInvalidator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DeferredWriteCacheInvalidator::class)]
final class DeferredWriteCacheInvalidatorTest extends TestCase
{
    /** @var string A representative query fingerprint. */
    private const string HASH = 'abc123';

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
    }

    /**
     * Test that invalidating a table on a taggable store flushes the table tag,
     * so a previously cached entry reads back as a miss.
     *
     * @return void
     */
    public function testInvalidateFlushesTaggableStore(): void
    {
        $warm = $this->cacheStore('widgets');
        $warm->put(self::HASH, collect(['a', 'b']), 2);

        self::assertNotNull($warm->get(self::HASH));

        (new DeferredWriteCacheInvalidator)->invalidate(['widgets']);

        self::assertNull($this->cacheStore('widgets')->get(self::HASH));
    }

    /**
     * Test that, on a non-taggable store with the registry enabled,
     * invalidation bumps the generational version so the entry misses.
     *
     * @return void
     */
    public function testInvalidateBumpsVersionOnNonTaggableStoreWhenRegistryEnabled(): void
    {
        Config::set('api-toolkit.repositories.cache.store', 'file');

        $warm = $this->cacheStore('widgets', 'file');
        $warm->put(self::HASH, collect(['a', 'b']), 2);

        (new DeferredWriteCacheInvalidator)->invalidate(['widgets']);

        self::assertNull($this->cacheStore('widgets', 'file')->get(self::HASH));
    }

    /**
     * Test that, on a non-taggable store with the registry disabled,
     * invalidation cannot bump the version, so the stale entry survives until
     * its TTL expires.
     *
     * @return void
     */
    public function testInvalidateLeavesStaleEntryWhenRegistryDisabledOnNonTaggableStore(): void
    {
        Config::set('api-toolkit.repositories.cache.store', 'file');
        Config::set('api-toolkit.repositories.cache.registry_enabled', false);

        $warm = $this->cacheStore('widgets', 'file');
        $warm->put(self::HASH, collect(['a', 'b']), 2);

        (new DeferredWriteCacheInvalidator)->invalidate(['widgets']);

        self::assertNotNull($this->cacheStore('widgets', 'file')->get(self::HASH));
    }

    /**
     * Test that every table in the list is invalidated, not just the first.
     *
     * @return void
     */
    public function testInvalidateInvalidatesEachOfMultipleTables(): void
    {
        $this->cacheStore('widgets')->put(self::HASH, collect(['a']), 1);
        $this->cacheStore('gadgets')->put(self::HASH, collect(['b']), 1);

        (new DeferredWriteCacheInvalidator)->invalidate(['widgets', 'gadgets']);

        self::assertNull($this->cacheStore('widgets')->get(self::HASH));
        self::assertNull($this->cacheStore('gadgets')->get(self::HASH));
    }

    /**
     * Test that an empty table list is a no-op that leaves existing cache
     * entries untouched.
     *
     * @return void
     */
    public function testInvalidateWithEmptyTableListLeavesEntryIntact(): void
    {
        $this->cacheStore('widgets')->put(self::HASH, collect(['a']), 1);

        (new DeferredWriteCacheInvalidator)->invalidate([]);

        self::assertNotNull($this->cacheStore('widgets')->get(self::HASH));
    }

    /**
     * Test that invalidation targets the configured repository cache store
     * rather than only the framework default.
     *
     * @return void
     */
    public function testInvalidateUsesConfiguredRepositoryStore(): void
    {
        Config::set('cache.stores.repo-cache', ['driver' => 'array']);
        Config::set('api-toolkit.repositories.cache.store', 'repo-cache');

        $options = new CacheStoreOptions(3600, new CacheSizeGuard(null, null), true, 0);

        (new CacheStore('repo-cache', 'widgets', $options))->put(self::HASH, collect(['a']), 1);

        (new DeferredWriteCacheInvalidator)->invalidate(['widgets']);

        self::assertNull((new CacheStore('repo-cache', 'widgets', $options))->get(self::HASH));
    }

    /**
     * Test that a non-string store configuration falls back to the array driver
     * rather than passing a non-string to the cache manager.
     *
     * @return void
     */
    public function testInvalidateFallsBackToArrayStoreForNonStringStoreConfig(): void
    {
        Config::set('api-toolkit.repositories.cache.store', null);
        Config::set('cache.default', 123);

        $this->cacheStore('widgets')->put(self::HASH, collect(['a']), 1);

        (new DeferredWriteCacheInvalidator)->invalidate(['widgets']);

        self::assertNull($this->cacheStore('widgets')->get(self::HASH));
    }

    /**
     * Build a CacheStore on the given driver for the given table.
     *
     * @param  string  $table
     * @param  string  $store
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\CacheStore
     */
    private function cacheStore(string $table, string $store = 'array'): CacheStore
    {
        return new CacheStore($store, $table, new CacheStoreOptions(3600, new CacheSizeGuard(null, null), true, 0));
    }
}
