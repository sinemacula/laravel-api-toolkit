<?php

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Concerns\Cacheable;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Repositories\CacheableTagRepository;
use Tests\Fixtures\Repositories\CustomStoreCacheableTagRepository;
use Tests\Fixtures\Repositories\ShortTtlTagRepository;
use Tests\TestCase;

/**
 * Tests for the Cacheable trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Cacheable::class)]
class CacheableTest extends TestCase
{
    /** @var \Tests\Fixtures\Repositories\CacheableTagRepository The repository under test. */
    private CacheableTagRepository $repository;

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

        assert($this->app !== null);

        $this->repository = $this->app->make(CacheableTagRepository::class);
    }

    /**
     * Test that the first read populates the cache and returns results.
     *
     * @return void
     */
    public function testFirstReadPopulatesCacheAndReturnsResults(): void
    {
        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertInstanceOf(Collection::class, $result);
        static::assertCount(2, $result);
        static::assertTrue($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that the second read returns cached data without executing a
     * new database query.
     *
     * @return void
     */
    public function testSecondReadReturnsCachedDataWithoutDatabaseQuery(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        Tag::create(['name' => 'vue']);

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertInstanceOf(Collection::class, $result);
        static::assertCount(2, $result);
    }

    /**
     * Test that a write operation flushes the cache.
     *
     * @return void
     */
    public function testWriteOperationFlushesCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $this->repository->scopeById(1)->update(['name' => 'updated']); // @phpstan-ignore staticMethod.dynamicCall

        static::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that withoutCache bypasses the cache without invalidating it.
     *
     * @return void
     */
    public function testWithoutCacheBypassesCacheWithoutInvalidating(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $result = $this->repository->withoutCache()->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertInstanceOf(Collection::class, $result);
        static::assertTrue($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that withoutCache is transient and only applies to the next
     * read.
     *
     * @return void
     */
    public function testWithoutCacheIsTransient(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        Tag::create(['name' => 'vue']);

        $this->repository->withoutCache()->get(); // @phpstan-ignore staticMethod.dynamicCall

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(2, $result);
    }

    /**
     * Test that flushCache clears populated cache.
     *
     * @return void
     */
    public function testFlushCacheClearsPopulatedCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $this->repository->flushCache();

        static::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that getCacheStatus returns accurate state transitions.
     *
     * @return void
     */
    public function testGetCacheStatusReturnsAccurateState(): void
    {
        static::assertFalse($this->repository->getCacheStatus()->isPopulated());

        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertTrue($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that a custom TTL is respected and the cache expires.
     *
     * @return void
     */
    public function testCustomTtlIsRespected(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(ShortTtlTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertTrue($repository->getCacheStatus()->isPopulated());

        $this->travel(6)->seconds();

        static::assertFalse($repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that a custom cache store name is used.
     *
     * @return void
     */
    public function testCustomCacheStoreNameIsUsed(): void
    {
        Config::set('cache.stores.custom-test', [
            'driver' => 'array',
        ]);

        assert($this->app !== null);

        $repository = $this->app->make(CustomStoreCacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertTrue($repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that the default cache key prefix uses the model table name.
     *
     * @return void
     */
    public function testDefaultCacheKeyPrefixUsesTableName(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $cacheKey = 'api-toolkit:repository-cache:tags';

        /** @var \Illuminate\Cache\CacheManager $cacheManager */
        $cacheManager = app('cache');

        static::assertTrue($cacheManager->store('array')->has($cacheKey));
    }
}
