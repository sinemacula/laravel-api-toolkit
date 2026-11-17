<?php

declare(strict_types = 1);

namespace Tests\Feature\Caching;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\CacheableUserRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\CombinedSearchUserResource;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Feature tests proving criteria reads that differ by any one query parameter
 * cache under their own fingerprints.
 *
 * A shared key would serve the first request's rows to the second.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
#[CoversClass(ApiResourceCollection::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(QuerySurface::class)]
final class FilteredCacheFingerprintTest extends TestCase
{
    /**
     * Set up a cacheable users route and seed rows across two email domains.
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

        User::create(['name' => 'Alpha', 'email' => 'alpha@keep.com']);
        User::create(['name' => 'Bravo', 'email' => 'bravo@keep.com']);
        User::create(['name' => 'Charlie', 'email' => 'charlie@drop.com']);
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
     * Test that two distinct filters are cached under separate fingerprints.
     *
     * @return void
     */
    public function testDistinctFiltersAreCachedUnderSeparateFingerprints(): void
    {
        $keepUrl = '/cached-users?' . http_build_query(['filters' => json_encode(['name' => ['$in' => ['Alpha', 'Bravo']]])]);
        $dropUrl = '/cached-users?' . http_build_query(['filters' => json_encode(['name' => ['$in' => ['Charlie']]])]);

        $this->getJson($keepUrl)->assertOk()->assertJsonCount(2, 'data');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $keepRepeat = $this->getJson($keepUrl);

        $keepRepeat->assertOk();
        $keepRepeat->assertJsonCount(2, 'data');
        self::assertCount(0, DB::getQueryLog());
        self::assertSame(['Alpha', 'Bravo'], $keepRepeat->json('data.*.name'));

        DB::flushQueryLog();

        // A different filter must miss the first filter's entry
        $dropRead = $this->getJson($dropUrl);

        $dropRead->assertOk();
        $dropRead->assertJsonCount(1, 'data');
        self::assertNotCount(0, DB::getQueryLog());
        self::assertSame(['Charlie'], $dropRead->json('data.*.name'));
    }

    /**
     * Provide request pairs that differ by a single query parameter.
     *
     * @return iterable<string, array{class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>, array<string, mixed>, array<string, mixed>}>
     */
    public static function requestPairs(): iterable
    {
        $aggregates = ['fields' => ['users' => 'name,aggregates']];

        yield 'fields' => [UserResource::class, [], ['fields' => ['users' => 'id,name,organization']]];
        yield 'aggregates' => [UserResource::class, $aggregates, $aggregates + ['aggregates' => ['users' => 'posts_avg_id']]];
        yield 'order' => [UserResource::class, ['order' => 'name'], ['order' => 'name:desc']];
        yield 'search' => [CombinedSearchUserResource::class, ['search' => 'Alpha'], ['search' => 'Bravo']];
        yield 'limit' => [UserResource::class, ['limit' => 1], ['limit' => 2]];
    }

    /**
     * Test that a request differing from a cached one by a single parameter
     * misses, matches an uncached read, and is then served from the cache.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resource
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     * @return void
     */
    #[DataProvider('requestPairs')]
    public function testRequestDifferingByOneParameterIsCachedApart(string $resource, array $first, array $second): void
    {
        $this->app?->make(SearchDriverRegistry::class)->override(DB::connection()->getDriverName(), new PatternSearchDriver);

        Config::set('api-toolkit.search.unverified_connections', [DB::connection()->getName()]);

        Route::middleware(ParseApiQuery::class)->get('/pair/cached', function (CacheableUserRepository $repository) use ($resource): ApiResourceCollection {
            return new ApiResourceCollection($repository->usingResource($resource)->withApiCriteria()->get(), $resource); // @phpstan-ignore staticMethod.dynamicCall
        });

        Route::middleware(ParseApiQuery::class)->get('/pair/uncached', function (UserRepository $repository) use ($resource): ApiResourceCollection {
            return new ApiResourceCollection($repository->usingResource($resource)->withApiCriteria()->get(), $resource); // @phpstan-ignore staticMethod.dynamicCall
        });

        $cached = $this->getJson('/pair/cached?' . http_build_query($first))->assertOk();
        $query  = '?' . http_build_query($second);

        DB::enableQueryLog();

        $miss = $this->getJson('/pair/cached' . $query)->assertOk();

        self::assertNotCount(0, DB::getQueryLog());
        self::assertNotSame($cached->baseResponse->getContent(), $miss->baseResponse->getContent());
        self::assertSame($this->getJson('/pair/uncached' . $query)->assertOk()->baseResponse->getContent(), $miss->baseResponse->getContent());

        DB::flushQueryLog();

        $hit = $this->getJson('/pair/cached' . $query)->assertOk();

        self::assertCount(0, DB::getQueryLog());
        self::assertSame($miss->baseResponse->getContent(), $hit->baseResponse->getContent());
    }
}
