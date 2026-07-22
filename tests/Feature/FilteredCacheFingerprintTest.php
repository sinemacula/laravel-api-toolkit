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
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\CacheableUserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature test proving a filtered HTTP read caches under its own fingerprint
 * rather than the whole table.
 *
 * A filtered read is keyed by the executed query, so repeating one filter is a
 * zero-query hit returning only its rows, while a different filter issues fresh
 * queries and returns a distinct rowset. This guards the highest-value
 * correctness case: a whole-table collision would silently serve the wrong rows
 * for the second filter.
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
     * Set up each test with a cacheable users route and seeded rows split
     * across two email domains.
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
     * Test that two distinct filters are cached under separate fingerprints,
     * with no whole-table collision.
     *
     * @return void
     */
    public function testDistinctFiltersAreCachedUnderSeparateFingerprints(): void
    {
        $keepUrl = '/cached-users?' . http_build_query(['filters' => json_encode(['email' => ['$like' => '@keep.com']])]);
        $dropUrl = '/cached-users?' . http_build_query(['filters' => json_encode(['email' => ['$like' => '@drop.com']])]);

        // Warm the cache for the first filter.
        $this->getJson($keepUrl)->assertOk()->assertJsonCount(2, 'data');

        DB::enableQueryLog();
        DB::flushQueryLog();

        // Repeating the first filter is a zero-query hit returning its rows.
        $keepRepeat = $this->getJson($keepUrl);

        $keepRepeat->assertOk();
        $keepRepeat->assertJsonCount(2, 'data');
        self::assertCount(0, DB::getQueryLog());
        self::assertSame(['Alpha', 'Bravo'], $keepRepeat->json('data.*.name'));

        DB::flushQueryLog();

        // A different filter falls through to a fresh query with a distinct
        // rowset, proving it was not served from the first filter's entry nor a
        // whole-table snapshot.
        $dropRead = $this->getJson($dropUrl);

        $dropRead->assertOk();
        $dropRead->assertJsonCount(1, 'data');
        self::assertNotCount(0, DB::getQueryLog());
        self::assertSame(['Charlie'], $dropRead->json('data.*.name'));
    }
}
