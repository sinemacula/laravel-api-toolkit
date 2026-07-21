<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\CacheableUserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature test proving a route calling withoutCache() bypasses the warm cache
 * and re-queries the database.
 *
 * With the per-query cache warmed and a row inserted out of band (which does
 * not pass through the repository's write invalidation), the cached route still
 * serves the stale collection while the withoutCache() route issues a fresh
 * query and reflects the new row.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
#[CoversClass(ApiResourceCollection::class)]
final class WithoutCacheBypassTest extends TestCase
{
    /**
     * Set up each test with a cached route and a cache-bypassing route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');

        Route::middleware(ParseApiQuery::class)->get('/api/cached-users', function (CacheableUserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->get(); // @phpstan-ignore staticMethod.dynamicCall

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/api/fresh-users', function (CacheableUserRepository $repository): ApiResourceCollection {

            $users = $repository->withoutCache()->usingResource(FilterableUserResource::class)->withApiCriteria()->get(); // @phpstan-ignore staticMethod.dynamicCall

            return new ApiResourceCollection($users, FilterableUserResource::class);
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
     * Test that withoutCache() re-queries and reflects an out-of-band write the
     * warm cache would otherwise hide.
     *
     * @return void
     */
    public function testWithoutCacheReQueriesAndReflectsFreshRow(): void
    {
        // Warm the per-query cache: two rows.
        $this->getJson('/api/cached-users')->assertOk()->assertJsonCount(2, 'data');

        // Insert out of band, bypassing the repository's write invalidation.
        User::create(['name' => 'Carol', 'email' => 'carol@example.com']);

        // The cached route still serves the stale two-row collection.
        $this->getJson('/api/cached-users')->assertOk()->assertJsonCount(2, 'data');

        DB::enableQueryLog();
        DB::flushQueryLog();

        // withoutCache() issues a fresh query and reflects the new row.
        $fresh = $this->getJson('/api/fresh-users');

        $fresh->assertOk();
        $fresh->assertJsonCount(3, 'data');
        self::assertNotCount(0, DB::getQueryLog());
        self::assertContains('carol@example.com', $fresh->json('data.*.email'));
    }
}
