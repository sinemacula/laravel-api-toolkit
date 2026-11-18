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
 * Feature tests proving a repeated identical HTTP read is served from the
 * per-query cache, and that a paginated read never is.
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
     * Set up each test with cacheable users routes and seeded rows.
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

        Route::middleware(ParseApiQuery::class)->get('/cached-users/paged', function (CacheableUserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

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
     * no queries.
     *
     * @return void
     */
    public function testSecondIdenticalReadServesFromCacheWithZeroQueries(): void
    {
        $first = $this->getJson('/cached-users');

        $first->assertOk();
        $first->assertJsonCount(3, 'data');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $second = $this->getJson('/cached-users');

        $second->assertOk();

        self::assertCount(0, DB::getQueryLog());

        self::assertSame($first->baseResponse->getContent(), $second->baseResponse->getContent());
    }

    /**
     * Test that a repeated identical paginated read runs its count and page
     * queries again.
     *
     * @return void
     */
    public function testRepeatedPaginatedReadRunsItsQueriesAgain(): void
    {
        $first = $this->getJson('/cached-users/paged?limit=2');

        $first->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $second = $this->getJson('/cached-users/paged?limit=2');

        $second->assertOk();

        $queries = implode("\n", array_column(DB::getQueryLog(), 'query'));

        self::assertStringContainsString('count(*)', $queries);
        self::assertStringContainsString(' limit ', $queries);
        self::assertSame($first->baseResponse->getContent(), $second->baseResponse->getContent());
    }
}
