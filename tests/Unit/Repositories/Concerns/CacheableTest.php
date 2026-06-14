<?php

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter;
use SineMacula\ApiToolkit\Repositories\Concerns\Cacheable;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheSizeGuard;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheStoreOptions;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Repositories\CacheableTagRepository;
use Tests\Fixtures\Repositories\CustomPrefixCacheableTagRepository;
use Tests\Fixtures\Repositories\CustomStoreCacheableTagRepository;
use Tests\Fixtures\Repositories\ShortTtlTagRepository;
use Tests\Fixtures\Repositories\TunedCacheableTagRepository;
use Tests\TestCase;

/**
 * Tests for the Cacheable trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(Cacheable::class)]
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

        /** @var \Illuminate\Cache\CacheManager $cacheManager */
        $cacheManager = app('cache');

        static::assertTrue($cacheManager->store('custom-test')->has('api-toolkit:repository-cache-meta:tags'));
    }

    /**
     * Test that a custom cache key prefix is used instead of the model
     * table name.
     *
     * @return void
     */
    public function testCustomCacheKeyPrefixIsUsed(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(CustomPrefixCacheableTagRepository::class);

        $repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        /** @var \Illuminate\Cache\CacheManager $cacheManager */
        $cacheManager = app('cache');

        static::assertTrue($cacheManager->store('array')->has('api-toolkit:repository-cache-meta:custom-prefix'));
    }

    /**
     * Test that withoutCache reads fresh data from the database while
     * the cached snapshot remains stale.
     *
     * @return void
     */
    public function testWithoutCacheReadsFreshDataFromDatabase(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        Tag::create(['name' => 'vue']);

        $result = $this->repository->withoutCache()->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(3, $result);
    }

    /**
     * Test that boot invokes the parent boot chain so inherited
     * repository collaborators are initialized.
     *
     * @return void
     */
    public function testBootInvokesParentBootChain(): void
    {
        $reflection = new \ReflectionClass(ApiRepository::class);

        $attributeSetter = $reflection->getProperty('attributeSetter')->getValue($this->repository);

        static::assertInstanceOf(AttributeSetter::class, $attributeSetter);
    }

    /**
     * Test that the default cache TTL is exactly one hour.
     *
     * @return void
     */
    public function testDefaultCacheTtlIsOneHour(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $this->travel(3599)->seconds();

        static::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $this->travel(1)->seconds();

        static::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that the default cache key prefix uses the model table name.
     *
     * @return void
     */
    public function testDefaultCacheKeyPrefixUsesTableName(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $cacheKey = 'api-toolkit:repository-cache-meta:tags';

        /** @var \Illuminate\Cache\CacheManager $cacheManager */
        $cacheManager = app('cache');

        static::assertTrue($cacheManager->store('array')->has($cacheKey));
    }

    /**
     * Test that distinct scoped reads resolve distinct cached rows rather
     * than colliding on a single whole-table entry.
     *
     * @return void
     */
    public function testDistinctScopedReadsResolveDistinctRows(): void
    {
        $one = $this->repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall
        $two = $this->repository->scopeById(2)->first(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertSame('php', $one?->name); // @phpstan-ignore property.notFound
        static::assertSame('laravel', $two?->name); // @phpstan-ignore property.notFound
    }

    /**
     * Test that a cached scoped read is served from the cache on repeat
     * without returning a different scope's rows.
     *
     * @return void
     */
    public function testCachedScopedReadIsStablePerScope(): void
    {
        $this->repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall

        $repeat = $this->repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertSame('php', $repeat?->name); // @phpstan-ignore property.notFound
    }

    /**
     * Test that a by-id read never returns the full-table collection from the
     * cache (the correctness bug being fixed).
     *
     * @return void
     */
    public function testByIdReadNeverReturnsFullCollection(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $single = $this->repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertInstanceOf(Tag::class, $single);
        static::assertSame('php', $single->name); // @phpstan-ignore property.notFound
    }

    /**
     * Test that create() returning a Model invalidates the cache.
     *
     * @return void
     */
    public function testCreateReturningModelInvalidatesCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $created = $this->repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        static::assertInstanceOf(Tag::class, $created);
        static::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that a delete() write verb invalidates the cache.
     *
     * @return void
     */
    public function testDeleteInvalidatesCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $this->repository->scopeById(1)->delete(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that a fresh read repopulates the cache after a write
     * invalidation.
     *
     * @return void
     */
    public function testReadAfterWriteRepopulatesCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $this->repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertCount(3, $result);
        static::assertTrue($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that the cached find returns a sentinel miss that is invalidated
     * once the missing record is created.
     *
     * @return void
     */
    public function testCachedFindMissIsInvalidatedOnWrite(): void
    {
        $missing = $this->repository->scopeById(999)->first(); // @phpstan-ignore staticMethod.dynamicCall

        static::assertNull($missing);

        $this->repository->create(['name' => 'svelte']); // @phpstan-ignore staticMethod.dynamicCall

        $created = Tag::query()->where('name', 'svelte')->first();

        $found = $this->repository->scopeById($created?->id)->first(); // @phpstan-ignore staticMethod.dynamicCall, property.notFound

        static::assertInstanceOf(Tag::class, $found);
    }

    /**
     * Test that the row count used by the size guard is the collection size for
     * a collection, exactly one for a single model, and zero otherwise.
     *
     * @return void
     */
    public function testRowCountReflectsResultShape(): void
    {
        $rowCount = new \ReflectionMethod($this->repository, 'rowCount');

        static::assertSame(2, $rowCount->invoke($this->repository, new Collection(['a', 'b'])));
        static::assertSame(1, $rowCount->invoke($this->repository, new Tag));
        static::assertSame(0, $rowCount->invoke($this->repository, 'not-a-model'));
        static::assertSame(0, $rowCount->invoke($this->repository, null));
    }

    /**
     * Test that the reference-mode key argument is taken from the first
     * argument, defaults to zero, and preserves integer and string keys while
     * casting any other type to a string.
     *
     * @return void
     */
    public function testReferenceIdResolvesPrimaryKeyArgument(): void
    {
        $referenceId = new \ReflectionMethod($this->repository, 'referenceId');

        static::assertSame(5, $referenceId->invoke($this->repository, [5, 99]));
        static::assertSame('php', $referenceId->invoke($this->repository, ['php']));
        static::assertSame(0, $referenceId->invoke($this->repository, []));
        static::assertSame('1.5', $referenceId->invoke($this->repository, [1.5]));
    }

    /**
     * Test that the repository's cache tuning properties take precedence over
     * the package configuration when resolving the store options.
     *
     * @return void
     */
    public function testTuningPropertiesTakePrecedenceOverConfig(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(TunedCacheableTagRepository::class);

        $resolveTtl          = new \ReflectionMethod($repository, 'resolveTtl');
        $resolveReferenceTtl = new \ReflectionMethod($repository, 'resolveReferenceTtl');
        $resolveStoreOptions = new \ReflectionMethod($repository, 'resolveStoreOptions');

        static::assertSame(120, $resolveTtl->invoke($repository));
        static::assertSame(240, $resolveReferenceTtl->invoke($repository));

        $options = $resolveStoreOptions->invoke($repository);

        static::assertInstanceOf(CacheStoreOptions::class, $options);
        static::assertSame(120, $options->ttl);
        static::assertFalse($options->registryEnabled);
        static::assertSame(50, (new \ReflectionProperty(CacheSizeGuard::class, 'maxRows'))->getValue($options->sizeGuard));
        static::assertSame(2048, (new \ReflectionProperty(CacheSizeGuard::class, 'maxBytes'))->getValue($options->sizeGuard));
    }
}
