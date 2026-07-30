<?php

declare(strict_types = 1);

namespace Tests\Feature\Caching;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Concerns\Deferrable;
use SineMacula\ApiToolkit\Repositories\Concerns\DeferredWriteCacheInvalidator;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\CacheableUserRepository;
use Tests\Fixtures\Repositories\DeferrableUserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature test proving an HTTP write invalidates the per-query cache so the
 * next HTTP read envelope is fresh.
 *
 * A read warms the cached collection, a deferred create is buffered through a
 * route and flushed at the request boundary, and the boundary flush invalidates
 * the users-table cache. The second read re-queries and the client-visible
 * envelope reports the new total, joining the read and write halves over the
 * real response body rather than direct repository calls.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
#[CoversClass(ApiResourceCollection::class)]
#[CoversClass(DeferredWriteCacheInvalidator::class)]
#[CoversClass(WritePoolFlushSubscriber::class)]
#[CoversTrait(Deferrable::class)]
final class CacheInvalidationRoundTripTest extends TestCase
{
    /**
     * Set up each test with cacheable read and deferrable write routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Config::set('api-toolkit.deferred_writes.invalidate_query_cache', true);

        Route::middleware(ParseApiQuery::class)->get('/cached-users', function (CacheableUserRepository $repository): ApiResourceCollection {

            /** @var \Illuminate\Support\Collection<int, \Tests\Fixtures\Models\User> $users */
            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->get(); // @phpstan-ignore staticMethod.dynamicCall

            $paginator = new LengthAwarePaginator($users, $users->count(), max($users->count(), 1));

            return new ApiResourceCollection($paginator, FilterableUserResource::class);
        });

        Route::post('/deferred-users', function (DeferrableUserRepository $repository): JsonResponse {

            /** @var string $email */
            $email = request()->input('email');

            $repository->defer(['name' => 'Deferred', 'email' => $email]);

            return response()->json(['deferred' => $email], 202);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
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
     * Test that a deferred create flushed at the boundary invalidates the cache
     * so the next read envelope reports the new total.
     *
     * @return void
     */
    public function testBoundaryFlushMakesNextReadEnvelopeFresh(): void
    {
        // Warm the cached collection: two rows.
        $this->getJson('/cached-users')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data');

        // Defer a create; completing the request flushes the pool at the
        // boundary and invalidates the users-table cache.
        $this->postJson('/deferred-users', ['email' => 'carol@example.com'])->assertStatus(202);

        self::assertSame(3, DB::table('users')->count());

        // The stale cached collection is gone: the read re-queried and the
        // envelope now reflects the freshly persisted row.
        $fresh = $this->getJson('/cached-users');

        $fresh->assertOk();
        $fresh->assertJsonPath('meta.total', 3);
        $fresh->assertJsonCount(3, 'data');
        self::assertContains('carol@example.com', $fresh->json('data.*.email'));
    }
}
