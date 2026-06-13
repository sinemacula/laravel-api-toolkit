<?php

namespace Tests\Integration\Repositories;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Repositories\Concerns\Cacheable;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Repositories\CacheableTagRepository;
use Tests\Fixtures\Repositories\CustomStoreCacheableTagRepository;
use Tests\Fixtures\Repositories\FileStoreTagRepository;
use Tests\Fixtures\Repositories\ReferenceTableTagRepository;
use Tests\Fixtures\Repositories\SizeGuardedTagRepository;
use Tests\TestCase;

/**
 * Integration tests for per-query repository caching against a real database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Cacheable::class)]
class PerQueryCacheTest extends TestCase
{
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

        Tag::create(['name' => 'php']);
        Tag::create(['name' => 'laravel']);
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        DB::disableQueryLog();

        parent::tearDown();
    }

    /**
     * Test that a cache miss executes exactly one database query.
     *
     * @return void
     */
    public function testCacheMissExecutesSingleQuery(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        DB::enableQueryLog();

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(1, DB::getQueryLog());
    }

    /**
     * Test that a cache hit executes zero database queries, proving the
     * pre-execution interception.
     *
     * @return void
     */
    public function testCacheHitExecutesZeroQueries(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that a scoped hit executes zero queries while resolving the scoped
     * rows rather than the full table.
     *
     * @return void
     */
    public function testScopedCacheHitExecutesZeroQueries(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $tag = $repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(0, DB::getQueryLog());
        static::assertSame('php', $tag?->name); // @phpstan-ignore property.notFound
    }

    /**
     * Test that an oversized result always hits the database because the size
     * guard skips storing it.
     *
     * @return void
     */
    public function testSizeGuardedResultAlwaysHitsDatabase(): void
    {
        $repository = $this->makeRepository(SizeGuardedTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(1, DB::getQueryLog());
    }

    /**
     * Test that a write invalidation forces a fresh query on the next read.
     *
     * @return void
     */
    public function testWriteInvalidationForcesFreshQuery(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(1, DB::getQueryLog());
        static::assertCount(3, $result);
    }

    /**
     * Test that reference mode loads the whole table once for mixed reads.
     *
     * @return void
     */
    public function testReferenceModeLoadsTableOnceForMixedReads(): void
    {
        $repository = $this->makeRepository(ReferenceTableTagRepository::class);

        DB::enableQueryLog();

        $repository->all(); // @phpstan-ignore method.notFound
        $repository->find(1); // @phpstan-ignore staticMethod.dynamicCall
        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(1, DB::getQueryLog());
    }

    /**
     * Test that a non-taggable file store invalidates per-query entries via
     * the registry on a write.
     *
     * @return void
     */
    public function testFileStoreInvalidatesViaRegistryOnWrite(): void
    {
        $repository = $this->makeRepository(FileStoreTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(1, DB::getQueryLog());
        static::assertCount(3, $result);
    }

    /**
     * Test that, with the registry disabled, a non-taggable file store keeps
     * serving the stale entry after a write (TTL-only staleness).
     *
     * @return void
     */
    public function testFileStoreWithRegistryDisabledServesStaleEntry(): void
    {
        Config::set('api-toolkit.repositories.cache.registry_enabled', false);

        $repository = $this->makeRepository(FileStoreTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(0, DB::getQueryLog());
        static::assertCount(2, $result);
    }

    /**
     * Test that firstOrCreate invalidates the cache when it inserts a new row.
     *
     * @return void
     */
    public function testFirstOrCreateInvalidatesCacheOnInsert(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->firstOrCreate(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(1, DB::getQueryLog());
        static::assertCount(3, $result);
    }

    /**
     * Test that updateOrCreate invalidates the cache when it upserts a row.
     *
     * @return void
     */
    public function testUpdateOrCreateInvalidatesCache(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $repository->updateOrCreate(['name' => 'php'], ['name' => 'php8']); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(1, DB::getQueryLog());
        static::assertSame('php8', $result->firstWhere('name', 'php8')?->name); // @phpstan-ignore property.notFound
    }

    /**
     * Test that a repeated null find re-queries the database each time (null
     * results are not cached).
     *
     * @return void
     */
    public function testNullFindReQueriesOnEveryCall(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        $repository->find(999); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $repository->find(999); // @phpstan-ignore staticMethod.dynamicCall

        static::assertNull($result);
        static::assertCount(1, DB::getQueryLog());
    }

    /**
     * Test that after a null find, once the row is created the next read
     * returns the new row without a stale null being served from cache.
     *
     * @return void
     */
    public function testNullFindReflectsRowOncCreated(): void
    {
        $repository = $this->makeRepository(CacheableTagRepository::class);

        static::assertNull($repository->find(999)); // @phpstan-ignore staticMethod.dynamicCall

        Tag::create(['name' => 'vue']);

        $result = $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(3, $result);
    }

    /**
     * Test that the central CacheManager flush does not evict the per-query
     * repository cache when it uses the recommended dedicated store.
     *
     * @return void
     */
    public function testCacheManagerFlushDoesNotEvictRepositoryCache(): void
    {
        Config::set('cache.stores.custom-test', ['driver' => 'array']);

        $repository = $this->makeRepository(CustomStoreCacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertTrue($repository->getCacheStatus()->isPopulated());

        assert($this->app !== null);

        $this->app->make(CacheManager::class)->flush();

        DB::enableQueryLog();

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(0, DB::getQueryLog());
        static::assertTrue($repository->getCacheStatus()->isPopulated());
    }

    /**
     * Resolve a fresh cacheable repository from the container.
     *
     * @template TRepository of object
     *
     * @param  class-string<TRepository>  $class
     * @return TRepository
     */
    private function makeRepository(string $class): object
    {
        assert($this->app !== null);

        Cache::store('file')->clear();

        return $this->app->make($class);
    }
}
