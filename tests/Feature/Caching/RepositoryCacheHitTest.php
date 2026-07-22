<?php

declare(strict_types = 1);

namespace Tests\Feature\Caching;

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
 * Feature test proving a repeated identical HTTP read is served from the
 * per-query cache without touching the database.
 *
 * Two identical requests travel through the real ParseApiQuery middleware into
 * a Cacheable repository read. The first request warms the per-query cache; the
 * second returns a byte-identical envelope while the database query log stays
 * empty, proving the cache hit is observable end to end rather than only
 * through a direct repository call.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
#[CoversClass(ApiResourceCollection::class)]
final class RepositoryCacheHitTest extends TestCase
{
    /**
     * Set up each test with a cacheable users route and seeded rows.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');

        Route::middleware(ParseApiQuery::class)->get('/cached-users', function (CacheableUserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->get(); // @phpstan-ignore staticMethod.dynamicCall

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);
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
     * Test that a second identical read serves a byte-identical envelope with
     * zero database queries.
     *
     * @return void
     */
    public function testSecondIdenticalReadServesFromCacheWithZeroQueries(): void
    {
        // Warm the per-query cache with the first read.
        $first = $this->getJson('/cached-users');

        $first->assertOk();
        $first->assertJsonCount(3, 'data');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $second = $this->getJson('/cached-users');

        $second->assertOk();

        // The repeat read did not touch the database.
        self::assertCount(0, DB::getQueryLog());

        // The envelope is byte-identical to the warmed response.
        self::assertSame($first->baseResponse->getContent(), $second->baseResponse->getContent());
    }
}
