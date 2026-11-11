<?php

declare(strict_types = 1);

namespace Tests\Integration\DeferredWrites;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\DeferredWriteCacheInvalidator;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Repositories\CacheableTagRepository;
use Tests\TestCase;

/**
 * Integration tests for query cache invalidation by a boundary pool flush.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DeferredWriteCacheInvalidator::class)]
#[CoversClass(WritePoolFlushSubscriber::class)]
final class DeferredWriteCacheInvalidationTest extends TestCase
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
     * Test that the boundary flush invalidates the deferred table's cache.
     *
     * @return void
     */
    public function testBoundaryFlushInvalidatesPerQueryCacheForDeferredTable(): void
    {
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', true);

        $this->warmTagCache();

        $this->deferTagAndCompleteBoundary('vue');

        DB::enableQueryLog();

        $result = $this->freshTagRepository()->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
        self::assertCount(3, $result);
    }

    /**
     * Test that with invalidation off the write persists but the read is stale.
     *
     * @return void
     */
    public function testBoundaryFlushSkipsInvalidationWhenDisabledYetStillPersists(): void
    {
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', false);

        $this->warmTagCache();

        $this->deferTagAndCompleteBoundary('vue');

        DB::enableQueryLog();

        $result = $this->freshTagRepository()->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(0, DB::getQueryLog());
        self::assertCount(2, $result);

        // The write still persisted; only the cache invalidation was skipped
        self::assertSame(3, DB::table('tags')->count());
    }

    /**
     * Warm the per-query cache for the tags table.
     *
     * @return void
     */
    private function warmTagCache(): void
    {
        $this->freshTagRepository()->get(); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Defer a tag insert and run the boundary flush via the subscriber.
     *
     * @param  string  $name
     * @return void
     */
    private function deferTagAndCompleteBoundary(string $name): void
    {
        assert($this->app !== null);

        $pool = $this->app->make(WritePool::class);
        $pool->add('tags', ['name' => $name]);

        (new WritePoolFlushSubscriber($this->app))->handleFlush();
    }

    /**
     * Resolve a fresh cacheable tag repository from the container.
     *
     * @return \Tests\Fixtures\Repositories\CacheableTagRepository
     */
    private function freshTagRepository(): CacheableTagRepository
    {
        assert($this->app !== null);

        return $this->app->make(CacheableTagRepository::class);
    }
}
