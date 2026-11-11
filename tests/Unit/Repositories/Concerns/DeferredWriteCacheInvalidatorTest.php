<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\DeferredWriteCacheInvalidator;
use SineMacula\Repositories\Concerns\CacheSizeGuard;
use SineMacula\Repositories\Concerns\CacheStore;
use SineMacula\Repositories\Concerns\CacheStoreOptions;
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
     * Test that invalidating a table on a taggable store flushes the table tag.
     *
     * @return void
     */
    public function testInvalidateFlushesTaggableStore(): void
    {
        $warm = $this->cacheStore('widgets');
        $warm->put(self::HASH, collect(['a', 'b']), 2);

        self::assertNotNull($warm->fetch(self::HASH));

        (new DeferredWriteCacheInvalidator)->invalidate(['widgets']);

        self::assertNull($this->cacheStore('widgets')->fetch(self::HASH));
    }

    /**
     * Test that a non-taggable store invalidates by bumping the version.
     *
     * @return void
     */
    public function testInvalidateBumpsVersionOnNonTaggableStoreWhenRegistryEnabled(): void
    {
        Config::set('repositories.cache.store', 'file');

        $warm = $this->cacheStore('widgets', 'file');
        $warm->put(self::HASH, collect(['a', 'b']), 2);

        (new DeferredWriteCacheInvalidator)->invalidate(['widgets']);

        self::assertNull($this->cacheStore('widgets', 'file')->fetch(self::HASH));
    }

    /**
     * Test that a non-taggable store without the registry keeps the entry.
     *
     * The flag is a falsy non-bool to cover stringy env values.
     *
     * @return void
     */
    public function testInvalidateLeavesStaleEntryWhenRegistryDisabledOnNonTaggableStore(): void
    {
        Config::set('repositories.cache.store', 'file');
        Config::set('repositories.cache.registry_enabled', 0);

        $warm = $this->cacheStore('widgets', 'file');
        $warm->put(self::HASH, collect(['a', 'b']), 2);

        (new DeferredWriteCacheInvalidator)->invalidate(['widgets']);

        self::assertNotNull($this->cacheStore('widgets', 'file')->fetch(self::HASH));
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

        self::assertNull($this->cacheStore('widgets')->fetch(self::HASH));
        self::assertNull($this->cacheStore('gadgets')->fetch(self::HASH));
    }

    /**
     * Test that an empty table list leaves existing cache entries untouched.
     *
     * @return void
     */
    public function testInvalidateWithEmptyTableListLeavesEntryIntact(): void
    {
        $this->cacheStore('widgets')->put(self::HASH, collect(['a']), 1);

        (new DeferredWriteCacheInvalidator)->invalidate([]);

        self::assertNotNull($this->cacheStore('widgets')->fetch(self::HASH));
    }

    /**
     * Test that invalidation targets the configured repository cache store.
     *
     * @return void
     */
    public function testInvalidateUsesConfiguredRepositoryStore(): void
    {
        Config::set('cache.stores.repo-cache', ['driver' => 'array']);
        Config::set('repositories.cache.store', 'repo-cache');

        $options = new CacheStoreOptions(3600, new CacheSizeGuard(null, null), true, 0);

        (new CacheStore(Cache::store('repo-cache'), 'widgets', $options))->put(self::HASH, collect(['a']), 1);

        (new DeferredWriteCacheInvalidator)->invalidate(['widgets']);

        self::assertNull((new CacheStore(Cache::store('repo-cache'), 'widgets', $options))->fetch(self::HASH));
    }

    /**
     * Test that a non-string store config falls back to the array driver.
     *
     * @return void
     */
    public function testInvalidateFallsBackToArrayStoreForNonStringStoreConfig(): void
    {
        Config::set('repositories.cache.store', null);
        Config::set('cache.default', 123);

        $this->cacheStore('widgets')->put(self::HASH, collect(['a']), 1);

        (new DeferredWriteCacheInvalidator)->invalidate(['widgets']);

        self::assertNull($this->cacheStore('widgets')->fetch(self::HASH));
    }

    /**
     * Build a CacheStore on the given driver for the given table.
     *
     * @param  string  $table
     * @param  string  $store
     * @return \SineMacula\Repositories\Concerns\CacheStore
     */
    private function cacheStore(string $table, string $store = 'array'): CacheStore
    {
        return new CacheStore(Cache::store($store), $table, new CacheStoreOptions(3600, new CacheSizeGuard(null, null), true, 0));
    }
}
