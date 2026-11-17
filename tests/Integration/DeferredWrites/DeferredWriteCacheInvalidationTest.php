<?php

declare(strict_types = 1);

namespace Tests\Integration\DeferredWrites;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\DeferredWriteCacheInvalidator;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use Tests\Concerns\CreatesCacheTables;
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
    use CreatesCacheTables;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Tag::create(['name' => 'php']);
        Tag::create(['name' => 'laravel']);
    }

    /**
     * Provide the taggable array store and the database store, which is not
     * taggable and so invalidates by replacing the table's version key.
     *
     * @return iterable<string, array{string}>
     */
    public static function storeProvider(): iterable
    {
        yield 'array store' => ['array'];
        yield 'database store' => ['database'];
    }

    /**
     * Test that the boundary flush invalidates the deferred table's cache.
     *
     * @param  string  $store
     * @return void
     */
    #[DataProvider('storeProvider')]
    public function testBoundaryFlushInvalidatesPerQueryCacheForDeferredTable(string $store): void
    {
        Config::set('cache.default', $store);
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', true);

        $this->warmTagCache();

        $this->deferTagAndCompleteBoundary('vue');

        self::assertCount(3, $this->freshTagRepository()->get()); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Test that with invalidation off the write persists but the read is stale.
     *
     * @param  string  $store
     * @return void
     */
    #[DataProvider('storeProvider')]
    public function testBoundaryFlushSkipsInvalidationWhenDisabledYetStillPersists(string $store): void
    {
        Config::set('cache.default', $store);
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', false);

        $this->warmTagCache();

        $this->deferTagAndCompleteBoundary('vue');

        self::assertCount(2, $this->freshTagRepository()->get()); // @phpstan-ignore staticMethod.dynamicCall

        // The write still persisted; only the cache invalidation was skipped
        self::assertSame(3, DB::table('tags')->count());
    }

    /**
     * Create the database cache store's tables before the test transaction
     * opens.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createCacheTables();
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
        $pool->add('testing', 'tags', ['name' => $name]);

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
