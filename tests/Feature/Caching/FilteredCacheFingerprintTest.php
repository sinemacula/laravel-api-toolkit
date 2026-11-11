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
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\CacheableUserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature test proving a filtered HTTP read caches under its own fingerprint.
 *
 * A whole-table key would serve the first filter's rows for the second.
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
}
