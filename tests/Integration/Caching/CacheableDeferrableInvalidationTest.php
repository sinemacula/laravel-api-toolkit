<?php

declare(strict_types = 1);

namespace Tests\Integration\Caching;

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
 * Integration tests for a Cacheable, Deferrable repository's own invalidation.
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
     * Test that a boundary flush invalidates the repository's own query cache.
     *
     * @return void
     */
    public function testBoundaryFlushInvalidatesOwnPerQueryCache(): void
    {
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', true);

        $this->warmCache();

        $this->postJson('/deferred-tags', ['name' => 'vue'])->assertStatus(202);

        self::assertSame(3, DB::table('tags')->count());

        DB::enableQueryLog();

        $result = $this->freshRepository()->get(); // @phpstan-ignore staticMethod.dynamicCall

        self::assertCount(1, DB::getQueryLog());
        self::assertCount(3, $result);
    }

    /**
     * Test that with invalidation off the write persists but the read is stale.
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

        self::assertCount(0, DB::getQueryLog());
        self::assertCount(2, $result);

        // The write still persisted; only the cache invalidation was skipped
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
     * Resolve a fresh Cacheable and Deferrable tag repository.
     *
     * @return \Tests\Fixtures\Repositories\CacheableDeferrableTagRepository
     */
    private function freshRepository(): CacheableDeferrableTagRepository
    {
        assert($this->app !== null);

        return $this->app->make(CacheableDeferrableTagRepository::class);
    }
}
