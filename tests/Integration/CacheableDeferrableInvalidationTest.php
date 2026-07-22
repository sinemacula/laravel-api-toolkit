<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use SineMacula\ApiToolkit\Repositories\Concerns\DeferredWriteCacheInvalidator;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Repositories\CacheableDeferrableTagRepository;
use Tests\TestCase;

/**
 * Integration tests for a repository that is both Cacheable and Deferrable
 * invalidating its own per-query cache at the request boundary.
 *
 * This exercises the full consumer path end to end: a read warms the combined
 * repository's per-query cache, a deferred create is buffered through the same
 * repository inside a route handler, and completing the request fires the real
 * RequestHandled event so the subscribed listener flushes the write pool and
 * invalidates the tags-table cache. Existing suites cover the cache-only repo,
 * the direct pool flush, and the request boundary separately; this joins them.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DeferredWriteCacheInvalidator::class)]
#[CoversClass(WritePoolFlushSubscriber::class)]
#[CoversTrait(Deferrable::class)]
final class CacheableDeferrableInvalidationTest extends TestCase
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

        Route::post('/deferred-tags', function (CacheableDeferrableTagRepository $repository): JsonResponse {

            /** @var string $name */
            $name = request()->input('name');

            $repository->defer(['name' => $name]);

            return response()->json(['deferred' => $name], 202);
        });

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
     * Test that a deferred write flushed at the request boundary invalidates
     * the combined repository's own per-query cache, so the next read
     * re-queries and reflects the freshly persisted row.
     *
     * @return void
     */
    public function testBoundaryFlushInvalidatesOwnPerQueryCache(): void
    {
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', true);

        // Warm the combined repository's per-query cache: an identical repeated
        // read would now be served from cache without a database round trip.
        $this->warmCache();

        // Defer a create through the same combined repository inside a route,
        // then complete the request so RequestHandled flushes the pool.
        $this->postJson('/deferred-tags', ['name' => 'vue'])->assertStatus(202);

        // The boundary flush persisted the deferred row.
        self::assertSame(3, DB::table('tags')->count());

        DB::enableQueryLog();

        $result = $this->freshRepository()->get(); // @phpstan-ignore staticMethod.dynamicCall

        // The stale cached collection is gone: the read re-queried the database
        // and now reflects the row the boundary flush persisted.
        self::assertCount(1, DB::getQueryLog());
        self::assertCount(3, $result);
    }

    /**
     * Test that, with invalidation disabled, the boundary flush still persists
     * the deferred row but leaves the combined repository's cached collection
     * stale until its TTL expires.
     *
     * @return void
     */
    public function testBoundaryFlushLeavesCacheStaleWhenInvalidationDisabled(): void
    {
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', false);

        $this->warmCache();

        $this->postJson('/deferred-tags', ['name' => 'vue'])->assertStatus(202);

        DB::enableQueryLog();

        $result = $this->freshRepository()->get(); // @phpstan-ignore staticMethod.dynamicCall

        // Invalidation was disabled, so the warmed collection is served
        // straight from cache without a database round trip and stays stale
        // until its TTL expires.
        self::assertCount(0, DB::getQueryLog());
        self::assertCount(2, $result);

        // The deferred write itself still reached the database; only the
        // downstream cache invalidation was skipped.
        self::assertSame(3, DB::table('tags')->count());
    }

    /**
     * Warm the combined repository's per-query cache for the tags table.
     *
     * @return void
     */
    private function warmCache(): void
    {
        $this->freshRepository()->get(); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Resolve a fresh Cacheable and Deferrable tag repository from the
     * container.
     *
     * @return \Tests\Fixtures\Repositories\CacheableDeferrableTagRepository
     */
    private function freshRepository(): CacheableDeferrableTagRepository
    {
        assert($this->app !== null);

        return $this->app->make(CacheableDeferrableTagRepository::class);
    }
}
