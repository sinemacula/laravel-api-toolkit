<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\CacheKeys;
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

        self::assertInstanceOf(Collection::class, $cached);
        self::assertSame(['foo', 'bar', 'baz'], $cached->all());
    }

    /**
     * Test that a negatively cached miss is present yet reads back as null.
     *
     * @return void
     */
    public function testPutMissStoresMarkerThatReadsBackAsNull(): void
    {
        $this->cacheStore->putMiss(self::HASH);

        self::assertTrue($this->cacheStore->has(self::HASH));
        self::assertNull($this->cacheStore->get(self::HASH));
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

        self::assertTrue($this->cacheStore->has(self::HASH));

        // 11 seconds on: past the 10s negative TTL but well within the 3600s
        // TTL.
        Carbon::setTestNow(Carbon::parse('2026-03-09 12:00:11'));

        self::assertFalse($this->cacheStore->has(self::HASH));
        self::assertTrue($this->cacheStore->has('positive'));
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

        self::assertFalse($this->cacheStore->has(self::HASH));
    }

    /**
     * Test that get returns null on a cache miss.
     *
     * @return void
     */
    public function testGetReturnsNullOnCacheMiss(): void
    {
        self::assertNull($this->cacheStore->get(self::HASH));
    }

    /**
     * Test that has reflects whether an entry exists for a fingerprint.
     *
     * @return void
     */
    public function testHasReflectsEntryPresence(): void
    {
        self::assertFalse($this->cacheStore->has(self::HASH));

        $this->cacheStore->put(self::HASH, collect(['item']), 1);

        self::assertTrue($this->cacheStore->has(self::HASH));
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

        self::assertInstanceOf(Collection::class, $first);
        self::assertInstanceOf(Collection::class, $second);
        self::assertSame(['a'], $first->all());
        self::assertSame(['b', 'c'], $second->all());
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

        self::assertIsArray($meta);
        self::assertArrayHasKey('populated_at', $meta);
        self::assertSame(now()->timestamp, $meta['populated_at']);
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

        self::assertNull($store->get(self::HASH));
        self::assertFalse($store->has(self::HASH));
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

        self::assertNull($store->get(self::HASH));
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

        self::assertNull($this->cacheStore->get(self::HASH));
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

        self::assertNotNull($store->get(self::HASH));

        $store->flushTable();

        self::assertNull($store->get(self::HASH));
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

        self::assertNull($store->getStore()->get($versionKey));

        $store->flushTable();

        self::assertSame(1, $store->getStore()->get($versionKey));
        self::assertNull($store->get(self::HASH));

        $store->put(self::HASH, collect(['second']), 1);

        self::assertNotNull($store->get(self::HASH));

        $store->flushTable();

        self::assertSame(2, $store->getStore()->get($versionKey));
        self::assertNull($store->get(self::HASH));
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

        self::assertNotNull($store->get(self::HASH));
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

        self::assertIsArray($meta);
        self::assertArrayHasKey('invalidated_at', $meta);
        self::assertSame(now()->timestamp, $meta['invalidated_at']);
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

        self::assertTrue($status->isPopulated());
        self::assertSame(30, $status->getAge());
        self::assertNull($status->getLastInvalidatedAt());
    }

    /**
     * Test that getStatus reports an unpopulated state on a cache miss.
     *
     * @return void
     */
    public function testGetStatusReportsUnpopulatedStateOnCacheMiss(): void
    {
        $status = $this->cacheStore->getStatus();

        self::assertFalse($status->isPopulated());
        self::assertNull($status->getAge());
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

        self::assertFalse($status->isPopulated());
        self::assertNotNull($status->getLastInvalidatedAt());
        self::assertSame(now()->timestamp, $status->getLastInvalidatedAt()->timestamp);
    }

    /**
     * Test that getStore returns the underlying cache repository.
     *
     * @return void
     */
    public function testGetStoreReturnsUnderlyingCacheRepository(): void
    {
        self::assertInstanceOf(CacheContract::class, $this->cacheStore->getStore());
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

        self::assertNotNull($store->get(self::HASH));

        $store->flushTable();

        self::assertNull($store->get(self::HASH));
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

        self::assertNull($tableA->get(self::HASH));
        self::assertNotNull($tableB->get(self::HASH));
    }

    /**
     * Test that invalidateTable flushes a taggable store's table tag and,
     * because the taggable path returns early, never bumps the generational
     * version.
     *
     * @return void
     */
    public function testInvalidateTableFlushesTaggableStoreWithoutBumpingVersion(): void
    {
        $this->cacheStore->put(self::HASH, collect(['a', 'b']), 2);

        CacheStore::invalidateTable('array', 'test-table', true);

        self::assertNull($this->cacheStore->get(self::HASH));
        self::assertNull(Cache::store('array')->get(CacheKeys::REPOSITORY_CACHE_VERSION->resolveKey(['test-table'])));
    }

    /**
     * Test that invalidateTable bumps the generational version on a
     * non-taggable store when the registry is enabled, so a previously cached
     * entry reads back as a miss.
     *
     * @return void
     */
    public function testInvalidateTableBumpsVersionOnNonTaggableStoreWhenRegistryEnabled(): void
    {
        $options = new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10);
        (new CacheStore('file', 'file-table', $options))->put(self::HASH, collect(['a']), 1);

        CacheStore::invalidateTable('file', 'file-table', true);

        self::assertNull((new CacheStore('file', 'file-table', $options))->get(self::HASH));
    }

    /**
     * Test that invalidateTable leaves a non-taggable store's entry intact when
     * the registry is disabled, because it cannot bump the version.
     *
     * @return void
     */
    public function testInvalidateTableLeavesEntryOnNonTaggableStoreWhenRegistryDisabled(): void
    {
        $options = new CacheStoreOptions(3600, new CacheSizeGuard(1000, 262144), true, 10);
        (new CacheStore('file', 'file-table', $options))->put(self::HASH, collect(['a']), 1);

        CacheStore::invalidateTable('file', 'file-table', false);

        self::assertNotNull((new CacheStore('file', 'file-table', $options))->get(self::HASH));
    }
}
