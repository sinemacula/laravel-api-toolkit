<?php

namespace Tests\Unit\Repositories\Concerns;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheStore;
use Tests\TestCase;

/**
 * Tests for the CacheStore collaborator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheStore::class)]
class CacheStoreTest extends TestCase
{
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

        $this->cacheStore = new CacheStore('array', 'test-table', 3600);
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
     * Test that get returns the cached collection when populated.
     *
     * @return void
     */
    public function testGetReturnsCachedCollectionWhenPopulated(): void
    {
        $items = collect(['foo', 'bar', 'baz']);

        $this->cacheStore->put($items);

        $cached = $this->cacheStore->get();

        static::assertInstanceOf(Collection::class, $cached);
        static::assertSame(['foo', 'bar', 'baz'], $cached->all());
    }

    /**
     * Test that get returns null on a cache miss.
     *
     * @return void
     */
    public function testGetReturnsNullOnCacheMiss(): void
    {
        static::assertNull($this->cacheStore->get());
    }

    /**
     * Test that put stores the collection in the cache.
     *
     * @return void
     */
    public function testPutStoresCollectionInCache(): void
    {
        $items = collect(['alpha', 'beta']);

        $this->cacheStore->put($items);

        $store  = $this->cacheStore->getStore();
        $cached = $store->get('api-toolkit:repository-cache:test-table');

        static::assertInstanceOf(Collection::class, $cached);
        static::assertSame(['alpha', 'beta'], $cached->all());
    }

    /**
     * Test that put records populated_at metadata.
     *
     * @return void
     */
    public function testPutRecordsPopulatedAtMetadata(): void
    {
        $this->cacheStore->put(collect(['item']));

        $store = $this->cacheStore->getStore();
        $meta  = $store->get('api-toolkit:repository-cache-meta:test-table');

        static::assertIsArray($meta);
        static::assertArrayHasKey('populated_at', $meta);
        static::assertSame(now()->timestamp, $meta['populated_at']);
    }

    /**
     * Test that flush removes the cached data.
     *
     * @return void
     */
    public function testFlushRemovesCachedData(): void
    {
        $this->cacheStore->put(collect(['item']));
        $this->cacheStore->flush();

        static::assertNull($this->cacheStore->get());
    }

    /**
     * Test that flush records invalidated_at metadata.
     *
     * @return void
     */
    public function testFlushRecordsInvalidatedAtMetadata(): void
    {
        $this->cacheStore->flush();

        $store = $this->cacheStore->getStore();
        $meta  = $store->get('api-toolkit:repository-cache-meta:test-table');

        static::assertIsArray($meta);
        static::assertArrayHasKey('invalidated_at', $meta);
        static::assertSame(now()->timestamp, $meta['invalidated_at']);
    }

    /**
     * Test that getStatus returns a populated status when the cache
     * holds data.
     *
     * @return void
     */
    public function testGetStatusReturnsPopulatedStatusWhenCacheHasData(): void
    {
        $this->cacheStore->put(collect(['item']));

        Carbon::setTestNow(Carbon::parse('2026-03-09 12:00:30'));

        $status = $this->cacheStore->getStatus();

        static::assertTrue($status->isPopulated());
        static::assertSame(30, $status->getAge());
        static::assertNull($status->getLastInvalidatedAt());
    }

    /**
     * Test that getStatus returns an unpopulated status on a cache
     * miss.
     *
     * @return void
     */
    public function testGetStatusReturnsUnpopulatedStatusOnCacheMiss(): void
    {
        $status = $this->cacheStore->getStatus();

        static::assertFalse($status->isPopulated());
        static::assertNull($status->getAge());
    }

    /**
     * Test that getStatus returns lastInvalidatedAt after a flush.
     *
     * @return void
     */
    public function testGetStatusReturnsLastInvalidatedAtAfterFlush(): void
    {
        $this->cacheStore->put(collect(['item']));
        $this->cacheStore->flush();

        $status = $this->cacheStore->getStatus();

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
}
