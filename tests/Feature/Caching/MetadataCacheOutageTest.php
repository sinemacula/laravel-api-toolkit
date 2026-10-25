<?php

declare(strict_types = 1);

namespace Tests\Feature\Caching;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Exceptions\MetadataCacheUnavailableException;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\CombinedSearchUserResource;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Feature tests for serving requests while the cache store is unreachable.
 *
 * Resolving resources and repositories reads and writes toolkit metadata
 * through the shared store. With the store unreachable, each request is still
 * served from metadata computed in-process, and the outage is reported once
 * rather than failing the request or reporting on every call.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MetadataCacheWriter::class)]
final class MetadataCacheOutageTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with repository-backed routes, seeded rows, and an
     * unreachable cache store.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        // A driver that verifies its index backing, so a search proves its
        // index through the shared store rather than being waived
        $this->app?->make(SearchDriverRegistry::class)->override(DB::connection()->getDriverName(), new PatternSearchDriver(verifiesIndexBacking: true));

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/searchable-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(CombinedSearchUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, CombinedSearchUserResource::class);
        });

        Route::patch('/users/{id}', static function (Request $request, string $id, UserRepository $repository): array {

            $user = User::query()->findOrFail($id);

            $repository->persist($user, ['status' => $request->input('status')]);

            return ['status' => $user->fresh()?->status];
        });

        User::create(['name' => 'Alpha', 'email' => 'alpha@smith.com', 'status' => 'active']);
        User::create(['name' => 'Bravo', 'email' => 'bravo@example.com', 'status' => 'active']);
        User::create(['name' => 'Charlie', 'email' => 'charlie@smith.com', 'status' => 'inactive']);

        Exceptions::fake();

        $this->useUnreachableCacheStore();
    }

    /**
     * Test that a filtered, sorted list request is served while the store is
     * unreachable, and the outage is reported once.
     *
     * @return void
     */
    public function testAListRequestIsServedWhileTheStoreIsUnreachable(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'filters' => json_encode(['name' => ['$in' => ['Alpha', 'Charlie']]]),
            'order'   => 'name:desc',
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'Charlie');
        $response->assertJsonPath('data.1.name', 'Alpha');
        $response->assertJsonPath('meta.total', 2);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(MetadataCacheUnavailableException::class);
    }

    /**
     * Test that a search request, which proves its index through the shared
     * store, is served while the store is unreachable.
     *
     * @return void
     */
    public function testASearchRequestIsServedWhileTheStoreIsUnreachable(): void
    {
        Config::set('api-toolkit.search.index_proof_ttl', 60);

        $response = $this->getJson('/searchable-users?' . http_build_query([
            'search' => 'smith',
            'order'  => 'name:asc',
        ]));

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
        $response->assertJsonPath('data.0.name', 'Alpha');
        $response->assertJsonPath('data.1.name', 'Charlie');

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(MetadataCacheUnavailableException::class);
    }

    /**
     * Test that a write through the repository, which reads the model's casts
     * through the shared store, is served while the store is unreachable.
     *
     * @return void
     */
    public function testARepositoryWriteIsServedWhileTheStoreIsUnreachable(): void
    {
        $id = User::query()->where('name', 'Bravo')->value('id');

        self::assertIsInt($id);

        $response = $this->patchJson('/users/' . $id, ['status' => 'banned']);

        $response->assertOk();
        $response->assertJsonPath('status', 'banned');

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(MetadataCacheUnavailableException::class);
    }
}
