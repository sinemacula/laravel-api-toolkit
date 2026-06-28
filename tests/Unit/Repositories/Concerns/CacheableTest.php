<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter;
use SineMacula\ApiToolkit\Repositories\Concerns\Cacheable;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheSizeGuard;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheStore;
use SineMacula\ApiToolkit\Repositories\Concerns\CacheStoreOptions;
use Tests\Concerns\InteractsWithNonPublicMembers;
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
final class CacheableTest extends TestCase
{
    use InteractsWithNonPublicMembers;

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

        self::assertInstanceOf(Collection::class, $result);
        self::assertCount(2, $result);
        self::assertTrue($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that the second read returns cached data without executing a new
     * database query.
     *
     * @return void
     */
    public function testSecondReadReturnsCachedDataWithoutDatabaseQuery(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        Tag::create(['name' => 'vue']);

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Collection::class, $result);
        self::assertCount(2, $result);
    }

    /**
     * Test that a write operation flushes the cache.
     *
     * @return void
     */
    public function testWriteOperationFlushesCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $this->repository->scopeById(1)->update(['name' => 'updated']); // @phpstan-ignore staticMethod.dynamicCall

        self::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that a cache-store failure during the post-write flush is swallowed
     * and logged, and the already-committed write still returns its result
     * rather than surfacing the flush error to the caller (who could retry and
     * duplicate the record).
     *
     * @return void
     */
    public function testWriteSwallowsAndLogsAPostWriteFlushFailure(): void
    {
        // Arrange - a store whose flush write blows up (e.g. a Redis outage
        // after the DB mutation has already committed).
        $store = \Mockery::mock(Store::class)->shouldIgnoreMissing();
        $store->shouldReceive('put')->andThrow(new \RuntimeException('redis down'));

        Cache::extend('throwing', fn (): Repository => new Repository($store));
        Config::set('cache.stores.throwing', ['driver' => 'throwing']);

        $failing = new CacheStore('throwing', 'tags', new CacheStoreOptions(3600, new CacheSizeGuard(null, null), false, 0));
        $this->setProperty($this->repository, 'cacheStore', $failing);

        Log::shouldReceive('error')
            ->once()
            ->with('Cache flush after write failed', \Mockery::on(
                static fn (array $context): bool => $context['exception'] instanceof \RuntimeException
                    && $context['exception']->getMessage() === 'redis down',
            ));

        // Act - the write commits; the flush throws but must not surface.
        $created = $this->repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        // Assert - the committed write returns its result despite the failure.
        self::assertInstanceOf(Tag::class, $created);
        self::assertSame('vue', $created->getAttribute('name'));
    }

    /**
     * Test that withoutCache bypasses the cache without invalidating it.
     *
     * @return void
     */
    public function testWithoutCacheBypassesCacheWithoutInvalidating(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $result = $this->repository->withoutCache()->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Collection::class, $result);
        self::assertTrue($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that withoutCache is transient and only applies to the next read.
     *
     * @return void
     */
    public function testWithoutCacheIsTransient(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        Tag::create(['name' => 'vue']);

        $this->repository->withoutCache()->get(); // @phpstan-ignore staticMethod.dynamicCall

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(2, $result);
    }

    /**
     * Test that flushCache clears populated cache.
     *
     * @return void
     */
    public function testFlushCacheClearsPopulatedCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $this->repository->flushCache();

        self::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that getCacheStatus returns accurate state transitions.
     *
     * @return void
     */
    public function testGetCacheStatusReturnsAccurateState(): void
    {
        self::assertFalse($this->repository->getCacheStatus()->isPopulated());

        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());
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

        self::assertTrue($repository->getCacheStatus()->isPopulated());

        $this->travel(6)->seconds();

        self::assertFalse($repository->getCacheStatus()->isPopulated());
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

        self::assertTrue($repository->getCacheStatus()->isPopulated());

        /** @var \Illuminate\Cache\CacheManager $cacheManager */
        $cacheManager = app('cache');

        self::assertTrue($cacheManager->store('custom-test')->has('api-toolkit:repository-cache-meta:tags'));
    }

    /**
     * Test that a custom cache key prefix is used instead of the model table
     * name.
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

        self::assertTrue($cacheManager->store('array')->has('api-toolkit:repository-cache-meta:custom-prefix'));
    }

    /**
     * Test that withoutCache reads fresh data from the database while the
     * cached snapshot remains stale.
     *
     * @return void
     */
    public function testWithoutCacheReadsFreshDataFromDatabase(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        Tag::create(['name' => 'vue']);

        $result = $this->repository->withoutCache()->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(3, $result);
    }

    /**
     * Test that boot invokes the parent boot chain so inherited repository
     * collaborators are initialized.
     *
     * @return void
     */
    public function testBootInvokesParentBootChain(): void
    {
        $reflection = new \ReflectionClass(ApiRepository::class);

        $attributeSetter = $reflection->getProperty('attributeSetter')->getValue($this->repository);

        self::assertInstanceOf(AttributeSetter::class, $attributeSetter);
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

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $this->travel(1)->seconds();

        self::assertFalse($this->repository->getCacheStatus()->isPopulated());
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

        self::assertTrue($cacheManager->store('array')->has($cacheKey));
    }

    /**
     * Test that distinct scoped reads resolve distinct cached rows rather than
     * colliding on a single whole-table entry.
     *
     * @return void
     */
    public function testDistinctScopedReadsResolveDistinctRows(): void
    {
        $one = $this->repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall
        $two = $this->repository->scopeById(2)->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertSame('php', $one?->name); // @phpstan-ignore property.notFound
        self::assertSame('laravel', $two?->name); // @phpstan-ignore property.notFound
    }

    /**
     * Test that a cached scoped read is served from the cache on repeat without
     * returning a different scope's rows.
     *
     * @return void
     */
    public function testCachedScopedReadIsStablePerScope(): void
    {
        $this->repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall

        $repeat = $this->repository->scopeById(1)->first(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertSame('php', $repeat?->name); // @phpstan-ignore property.notFound
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

        self::assertInstanceOf(Tag::class, $single);
        self::assertSame('php', $single->name); // @phpstan-ignore property.notFound
    }

    /**
     * Test that find() does not collide across distinct ids. The by-id
     * constraint is applied at execution time - after the base builder is
     * fingerprinted - so without folding the verb arguments into the cache key
     * find(2) would be served the cached find(1) record.
     *
     * @return void
     */
    public function testFindDoesNotCollideAcrossDistinctIds(): void
    {
        $first  = $this->repository->find(1); // @phpstan-ignore staticMethod.dynamicCall
        $second = $this->repository->find(2); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Tag::class, $first);
        self::assertInstanceOf(Tag::class, $second);
        self::assertSame('php', $first->name);      // @phpstan-ignore property.notFound
        self::assertSame('laravel', $second->name); // @phpstan-ignore property.notFound
    }

    /**
     * Test that a scalar value() read does not collide with a cached get(). The
     * two reads share an identical base builder, so without folding the verb
     * into the cache key value('name') would be served the cached get()
     * collection instead of the scalar column value.
     *
     * @return void
     */
    public function testScalarValueReadDoesNotCollideWithCachedGet(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        $cached = $this->repository->value('name');                 // @phpstan-ignore staticMethod.dynamicCall
        $fresh  = $this->repository->withoutCache()->value('name'); // @phpstan-ignore staticMethod.dynamicCall

        self::assertIsString($cached);     // A scalar column value, never the cached get() collection
        self::assertSame($fresh, $cached); // And the same value the database returns uncached
    }

    /**
     * Test that create() returning a Model invalidates the cache.
     *
     * @return void
     */
    public function testCreateReturningModelInvalidatesCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $created = $this->repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        self::assertInstanceOf(Tag::class, $created);
        self::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that a delete() write verb invalidates the cache.
     *
     * @return void
     */
    public function testDeleteInvalidatesCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertTrue($this->repository->getCacheStatus()->isPopulated());

        $this->repository->scopeById(1)->delete(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertFalse($this->repository->getCacheStatus()->isPopulated());
    }

    /**
     * Test that a fresh read repopulates the cache after a write invalidation.
     *
     * @return void
     */
    public function testReadAfterWriteRepopulatesCache(): void
    {
        $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall
        $this->repository->create(['name' => 'vue']); // @phpstan-ignore staticMethod.dynamicCall

        $result = $this->repository->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(3, $result);
        self::assertTrue($this->repository->getCacheStatus()->isPopulated());
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

        self::assertNull($missing);

        $this->repository->create(['name' => 'svelte']); // @phpstan-ignore staticMethod.dynamicCall

        $created = Tag::query()->where('name', 'svelte')->first();

        $found = $this->repository->scopeById($created?->id)->first(); // @phpstan-ignore staticMethod.dynamicCall, property.notFound

        self::assertInstanceOf(Tag::class, $found);
    }

    /**
     * Test that a null/miss read is negatively cached, so a repeated read for
     * the same missing key is served from the cache without a database query.
     *
     * @return void
     */
    public function testMissingReadIsServedFromNegativeCacheWithoutRequery(): void
    {
        self::assertNull($this->repository->find(999)); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        $result = $this->repository->find(999); // @phpstan-ignore staticMethod.dynamicCall

        DB::disableQueryLog();

        self::assertNull($result);
        self::assertCount(0, DB::getQueryLog());
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

        self::assertSame(2, $rowCount->invoke($this->repository, new Collection(['a', 'b'])));
        self::assertSame(1, $rowCount->invoke($this->repository, new Tag));
        self::assertSame(0, $rowCount->invoke($this->repository, 'not-a-model'));
        self::assertSame(0, $rowCount->invoke($this->repository, null));
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

        self::assertSame(5, $referenceId->invoke($this->repository, [5, 99]));
        self::assertSame('php', $referenceId->invoke($this->repository, ['php']));
        self::assertSame(0, $referenceId->invoke($this->repository, []));
        self::assertSame('1.5', $referenceId->invoke($this->repository, [1.5]));
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
        $resolveNegativeTtl  = new \ReflectionMethod($repository, 'resolveNegativeTtl');
        $resolveStoreOptions = new \ReflectionMethod($repository, 'resolveStoreOptions');

        self::assertSame(120, $resolveTtl->invoke($repository));
        self::assertSame(240, $resolveReferenceTtl->invoke($repository));
        self::assertSame(30, $resolveNegativeTtl->invoke($repository));

        $options = $resolveStoreOptions->invoke($repository);

        self::assertInstanceOf(CacheStoreOptions::class, $options);
        self::assertSame(120, $options->ttl);
        self::assertSame(30, $options->negativeTtl);
        self::assertFalse($options->registryEnabled);
        self::assertSame(50, (new \ReflectionProperty(CacheSizeGuard::class, 'maxRows'))->getValue($options->sizeGuard));
        self::assertSame(2048, (new \ReflectionProperty(CacheSizeGuard::class, 'maxBytes'))->getValue($options->sizeGuard));
    }

    /**
     * Test that the per-query and reference cache TTLs fall back to one hour
     * when neither a repository property nor numeric configuration is present.
     *
     * @return void
     */
    public function testCacheTtlsFallBackToOneHourForNonNumericConfig(): void
    {
        assert($this->app !== null);

        Config::set('api-toolkit.repositories.cache.ttl', 'not-numeric');
        Config::set('api-toolkit.repositories.cache.reference_ttl', 'not-numeric');

        $repository = $this->app->make(CacheableTagRepository::class);

        self::assertSame(3600, (new \ReflectionMethod($repository, 'resolveTtl'))->invoke($repository));
        self::assertSame(3600, (new \ReflectionMethod($repository, 'resolveReferenceTtl'))->invoke($repository));
    }

    /**
     * Test that the negative-lookup TTL casts a numeric configuration value to
     * an int and falls back to ten seconds for a non-numeric value.
     *
     * @return void
     */
    public function testNegativeTtlCastsNumericConfigAndFallsBackForNonNumeric(): void
    {
        assert($this->app !== null);

        $repository = $this->app->make(CacheableTagRepository::class);
        $resolve    = new \ReflectionMethod($repository, 'resolveNegativeTtl');

        Config::set('api-toolkit.repositories.cache.negative_ttl', '25');

        self::assertSame(25, $resolve->invoke($repository));

        Config::set('api-toolkit.repositories.cache.negative_ttl', 'not-numeric');

        self::assertSame(10, $resolve->invoke($repository));
    }
}
