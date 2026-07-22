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
use Tests\Fixtures\Repositories\ReferenceCacheUserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature test proving whole-table reference-cache mode over repeated HTTP
 * reads.
 *
 * A reference-mode repository serves the whole-table snapshot with zero queries
 * on a repeat read, but a criteria-composed read is an active composition that
 * bypasses the snapshot and falls through to the database, so a filtered
 * request never receives the unfiltered snapshot.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
#[CoversClass(ApiResourceCollection::class)]
final class ReferenceCacheModeTest extends TestCase
{
    /**
     * Set up each test with snapshot and criteria-composed reference routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');

        Route::middleware(ParseApiQuery::class)->get('/reference-users', function (ReferenceCacheUserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->get(); // @phpstan-ignore staticMethod.dynamicCall

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/reference-users-filtered', function (ReferenceCacheUserRepository $repository): ApiResourceCollection {

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
     * Test that a repeat snapshot read serves zero queries while a
     * criteria-composed read falls through to the database.
     *
     * @return void
     */
    public function testSnapshotReadHitsCacheWhileCriteriaReadFallsThrough(): void
    {
        // Warm the whole-table snapshot.
        $this->getJson('/reference-users')->assertOk()->assertJsonCount(3, 'data');

        DB::enableQueryLog();
        DB::flushQueryLog();

        // The repeat snapshot read is served with zero queries.
        $snapshot = $this->getJson('/reference-users');

        $snapshot->assertOk();
        $snapshot->assertJsonCount(3, 'data');
        self::assertCount(0, DB::getQueryLog());

        DB::flushQueryLog();

        // A criteria-composed read is an active composition: it bypasses the
        // snapshot, issues a fresh query, and narrows to the matching rows.
        $filtered = $this->getJson('/reference-users-filtered?' . http_build_query([
            'filters' => json_encode(['email' => ['$like' => '@keep.com']]),
        ]));

        $filtered->assertOk();
        $filtered->assertJsonCount(2, 'data');
        self::assertNotCount(0, DB::getQueryLog());
        self::assertSame(['Alpha', 'Bravo'], $filtered->json('data.*.name'));
    }
}
