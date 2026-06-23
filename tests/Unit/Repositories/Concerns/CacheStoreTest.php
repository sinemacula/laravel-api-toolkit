<?php

namespace Tests\Unit\Repositories\Concerns;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheSizeGuard;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheStore;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheStoreOptions;
use Tests\TestCase;

/**
 * Tests for the per-query CacheStore collaborator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheStore::class)]
final class CacheStoreTest extends TestCase
{
    /** @var string A representative query fingerprint. */
    private const string HASH = 'abc123';

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\CacheStore The cache store instance under test. */
    private CacheStore $cacheStore;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-09 12:00:00'));

        $this->cacheStore = new CacheStore('array', 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Test that a stored result round-trips for the same fingerprint.
     *
     * @return void
     */
    public function testPutAndGetRoundTripForSameFingerprint(): void
    {
        $items = collect(['foo', 'bar', 'baz']);

        $this->cacheStore->put(self::HASH, $items, $items->count());

        $cached = $this->cacheStore->get(self::HASH);

        static::assertInstanceOf(Collection::class, $cached);
        static::assertSame(['foo', 'bar', 'baz'], $cached->all());
    }

    /**
     * Test that a negatively cached miss is present yet reads back as null.
     *
     * @return void
     */
    public function testPutMissStoresMarkerThatReadsBackAsNull(): void
    {
        $this->cacheStore->putMiss(self::HASH);

        static::assertTrue($this->cacheStore->has(self::HASH));
        static::assertNull($this->cacheStore->get(self::HASH));
    }

    /**
     * Test that a negative entry expires after the shorter negative TTL while a
     * positive entry stored at the same moment survives on the full TTL.
     *
     * @return void
     */
    public function testPutMissExpiresAfterNegativeTtlNotFullTtl(): void
    {
        $this->cacheStore->putMiss(self::HASH);
        $this->cacheStore->put('positive', collect(['x']), 1);

        static::assertTrue($this->cacheStore->has(self::HASH));

        // 11 seconds on: past the 10s negative TTL but well within the 3600s TTL.
        Carbon::setTestNow(Carbon::parse('2026-03-09 12:00:11'));

        static::assertFalse($this->cacheStore->has(self::HASH));
        static::assertTrue($this->cacheStore->has('positive'));
    }

    /**
     * Test that a negative entry is invalidated by a table flush, so a write
     * does not leave a stale "not found" behind.
     *
     * @return void
     */
    public function testPutMissIsInvalidatedByFlushTable(): void
    {
        $this->cacheStore->putMiss(self::HASH);

        $this->cacheStore->flushTable();

        static::assertFalse($this->cacheStore->has(self::HASH));
    }

    /**
     * Test that get returns null on a cache miss.
     *
     * @return void
     */
    public function testGetReturnsNullOnCacheMiss(): void
    {
        static::assertNull($this->cacheStore->get(self::HASH));
    }

    /**
     * Test that has reflects whether an entry exists for a fingerprint.
     *
     * @return void
     */
    public function testHasReflectsEntryPresence(): void
    {
        static::assertFalse($this->cacheStore->has(self::HASH));

        $this->cacheStore->put(self::HASH, collect(['item']), 1);

        static::assertTrue($this->cacheStore->has(self::HASH));
    }

    /**
     * Test that distinct fingerprints map to distinct cache entries.
     *
     * @return void
     */
    public function testDistinctFingerprintsAreIsolated(): void
    {
        $this->cacheStore->put('hash-a', collect(['a']), 1);
        $this->cacheStore->put('hash-b', collect(['b', 'c']), 2);

        $first  = $this->cacheStore->get('hash-a');
        $second = $this->cacheStore->get('hash-b');

        static::assertInstanceOf(Collection::class, $first);
        static::assertInstanceOf(Collection::class, $second);
        static::assertSame(['a'], $first->all());
        static::assertSame(['b', 'c'], $second->all());
    }

    /**
     * Test that put records populated_at metadata.
     *
     * @return void
     */
    public function testPutRecordsPopulatedAtMetadata(): void
    {
        $this->cacheStore->put(self::HASH, collect(['item']), 1);

        $meta = $this->cacheStore->getStore()->get('api-toolkit:repository-cache-meta:test-table');

        static::assertIsArray($meta);
        static::assertArrayHasKey('populated_at', $meta);
        static::assertSame(now()->timestamp, $meta['populated_at']);
    }

    /**
     * Test that the size guard skips storing when the row count exceeds the
     * configured ceiling, while a get still misses.
     *
     * @return void
     */
    public function testSizeGuardSkipsStoringWhenRowCountExceeded(): void
    {
        $store = new CacheStore('array', 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(2, 262144), true, 10));

        $store->put(self::HASH, collect(['a', 'b', 'c']), 3);

        static::assertNull($store->get(self::HASH));
        static::assertFalse($store->has(self::HASH));
    }

    /**
     * Test that the size guard skips storing when the serialized byte size
     * exceeds the configured ceiling.
     *
     * @return void
     */
    public function testSizeGuardSkipsStoringWhenByteSizeExceeded(): void
    {
        $store = new CacheStore('array', 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 8), true, 10));

        $store->put(self::HASH, collect([str_repeat('x', 256)]), 1);

        static::assertNull($store->get(self::HASH));
    }

    /**
     * Test that flushTable removes a stored entry on a taggable store.
     *
     * @return void
     */
    public function testFlushTableRemovesStoredEntryOnTaggableStore(): void
    {
        $this->cacheStore->put(self::HASH, collect(['item']), 1);

        $this->cacheStore->flushTable();

        static::assertNull($this->cacheStore->get(self::HASH));
    }

    /**
     * Test that flushTable invalidates a stored entry via a generational
     * version bump on a non-taggable store.
     *
     * @return void
     */
    public function testFlushTableInvalidatesEntryViaVersionBumpOnNonTaggableStore(): void
    {
        $store = new CacheStore('file', 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        $store->put(self::HASH, collect(['item']), 1);

        static::assertNotNull($store->get(self::HASH));

        $store->flushTable();

        static::assertNull($store->get(self::HASH));
    }

    /**
     * Test that flushTable bumps the table's generational version on each call,
     * orphaning entries stored under the previous version while leaving newer
     * entries reachable - an O(1) invalidation with no tracked key set.
     *
     * @return void
     */
    public function testFlushTableBumpsGenerationalVersionOnNonTaggableStore(): void
    {
        $store      = new CacheStore('file', 'versioned-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));
        $versionKey = 'api-toolkit:repository-cache-version:versioned-table';

        $store->put(self::HASH, collect(['first']), 1);

        static::assertNull($store->getStore()->get($versionKey));

        $store->flushTable();

        static::assertSame(1, $store->getStore()->get($versionKey));
        static::assertNull($store->get(self::HASH));

        $store->put(self::HASH, collect(['second']), 1);

        static::assertNotNull($store->get(self::HASH));

        $store->flushTable();

        static::assertSame(2, $store->getStore()->get($versionKey));
        static::assertNull($store->get(self::HASH));
    }

    /**
     * Test that, with invalidation disabled, flushTable leaves a non-taggable
     * entry in place so staleness is governed by TTL only.
     *
     * @return void
     */
    public function testFlushTableLeavesEntryWhenRegistryDisabledOnNonTaggableStore(): void
    {
        $store = new CacheStore('file', 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), false, 10));

        $store->put(self::HASH, collect(['item']), 1);

        $store->flushTable();

        static::assertNotNull($store->get(self::HASH));
    }

    /**
     * Test that flushTable records invalidated_at metadata.
     *
     * @return void
     */
    public function testFlushTableRecordsInvalidatedAtMetadata(): void
    {
        $this->cacheStore->flushTable();

        $meta = $this->cacheStore->getStore()->get('api-toolkit:repository-cache-meta:test-table');

        static::assertIsArray($meta);
        static::assertArrayHasKey('invalidated_at', $meta);
        static::assertSame(now()->timestamp, $meta['invalidated_at']);
    }

    /**
     * Test that getStatus reports a populated state after a put.
     *
     * @return void
     */
    public function testGetStatusReportsPopulatedStateAfterPut(): void
    {
        $this->cacheStore->put(self::HASH, collect(['item']), 1);

        Carbon::setTestNow(Carbon::parse('2026-03-09 12:00:30'));

        $status = $this->cacheStore->getStatus();

        static::assertTrue($status->isPopulated());
        static::assertSame(30, $status->getAge());
        static::assertNull($status->getLastInvalidatedAt());
    }

    /**
     * Test that getStatus reports an unpopulated state on a cache miss.
     *
     * @return void
     */
    public function testGetStatusReportsUnpopulatedStateOnCacheMiss(): void
    {
        $status = $this->cacheStore->getStatus();

        static::assertFalse($status->isPopulated());
        static::assertNull($status->getAge());
    }

    /**
     * Test that getStatus reports lastInvalidatedAt after a flush.
     *
     * @return void
     */
    public function testGetStatusReportsLastInvalidatedAtAfterFlush(): void
    {
        $this->cacheStore->put(self::HASH, collect(['item']), 1);
        $this->cacheStore->flushTable();

        $status = $this->cacheStore->getStatus();

        static::assertFalse($status->isPopulated());
        static::assertNotNull($status->getLastInvalidatedAt());
        static::assertSame(now()->timestamp, $status->getLastInvalidatedAt()->timestamp);
    }

    /**
     * Test that getStore returns the underlying cache repository.
     *
     * @return void
     */
    public function testGetStoreReturnsUnderlyingCacheRepository(): void
    {
        static::assertInstanceOf(CacheContract::class, $this->cacheStore->getStore());
    }

    /**
     * Test that a taggable store flushes through its tag even when the key
     * registry is disabled, proving the tag path is selected for taggable
     * stores rather than the registry.
     *
     * @return void
     */
    public function testTaggableStoreFlushesViaTagsWhenRegistryDisabled(): void
    {
        $store = new CacheStore('array', 'test-table', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), false, 10));

        $store->put(self::HASH, collect(['item']), 1);

        static::assertNotNull($store->get(self::HASH));

        $store->flushTable();

        static::assertNull($store->get(self::HASH));
    }

    /**
     * Test that the per-table tag isolates entries between tables, so flushing
     * one table never invalidates another table's cached entries.
     *
     * @return void
     */
    public function testTagIsolatesEntriesBetweenTables(): void
    {
        $tableA = new CacheStore('array', 'table-a', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));
        $tableB = new CacheStore('array', 'table-b', new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10));

        $tableA->put(self::HASH, collect(['a']), 1);
        $tableB->put(self::HASH, collect(['b']), 1);

        $tableA->flushTable();

        static::assertNull($tableA->get(self::HASH));
        static::assertNotNull($tableB->get(self::HASH));
    }
}
