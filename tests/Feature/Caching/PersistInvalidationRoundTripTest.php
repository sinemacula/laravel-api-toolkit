<?php

declare(strict_types = 1);

namespace Tests\Feature\Caching;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\CacheablePostRepository;
use Tests\Fixtures\Repositories\CacheableUserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\TestCase;

/**
 * Feature test proving a write through persist() invalidates a cacheable
 * repository for the next HTTP read.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
final class PersistInvalidationRoundTripTest extends TestCase
{
    use RecordsStatements;

    /** @var string The read of every post with its tags */
    private const string POSTS_WITH_TAGS = '/cached-posts?fields[posts]=id,tags';

    /** @var \Tests\Fixtures\Models\User The user every test starts with */
    private User $alice;

    /**
     * Set up cacheable read routes and persist() write routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Config::set(ResourceDiscovery::RESOURCES_KEY, [FilterableUserResource::class, PostResource::class]);

        Route::middleware(ParseApiQuery::class)->group(static function (): void {
            Route::get('/cached-users', static fn (CacheableUserRepository $repository): ApiResourceCollection => self::collection($repository, FilterableUserResource::class));
            Route::get('/cached-posts', static fn (CacheablePostRepository $repository): ApiResourceCollection => self::collection($repository, PostResource::class));
        });

        Route::post('/cached-users', static function (Request $request, CacheableUserRepository $repository): JsonResponse {
            $repository->persist(new User, $request->only('name', 'email'));

            return response()->json(status: 201);
        });

        Route::patch('/cached-users/{user}', static function (Request $request, CacheableUserRepository $repository, string $user): JsonResponse {
            $repository->persist(User::query()->findOrFail($user), $request->only('name'));

            return response()->json();
        });

        Route::patch('/cached-posts/{post}', static function (Request $request, CacheablePostRepository $repository, string $post): JsonResponse {
            $repository->persist(Post::query()->findOrFail($post), $request->only('tags'));

            return response()->json();
        });

        $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    }

    /**
     * Test that an update through persist() is served by the next read.
     *
     * @return void
     */
    public function testUpdateThroughPersistIsServedByTheNextRead(): void
    {
        $this->assertCachedRead('/cached-users')->assertJsonPath('data.0.name', 'Alice');

        $this->patchJson('/cached-users/' . $this->alice->id, ['name' => 'Renamed'])->assertOk();

        $this->assertFreshRead('/cached-users')->assertJsonPath('data.0.name', 'Renamed');
    }

    /**
     * Test that a create through persist() is served by the next read.
     *
     * @return void
     */
    public function testCreateThroughPersistIsServedByTheNextRead(): void
    {
        $this->assertCachedRead('/cached-users')->assertJsonPath('meta.total', 1);

        $this->postJson('/cached-users', ['name' => 'Bob', 'email' => 'bob@example.com'])->assertCreated();

        $this->assertFreshRead('/cached-users')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['name' => 'Bob']);
    }

    /**
     * Test that a many-to-many sync through persist() is served by the next
     * read of the relation.
     *
     * @return void
     */
    public function testRelationSyncThroughPersistIsServedByTheNextRead(): void
    {
        $post    = Post::create(['user_id' => $this->alice->id, 'title' => 'T', 'body' => 'B']);
        $php     = Tag::create(['name' => 'php']);
        $laravel = Tag::create(['name' => 'laravel']);

        $post->tags()->attach($php);

        $this->assertCachedRead(self::POSTS_WITH_TAGS)->assertJsonPath('data.0.tags.*.name', ['php']);

        $this->patchJson('/cached-posts/' . $post->id, ['tags' => [$laravel->id]])->assertOk();

        $this->assertFreshRead(self::POSTS_WITH_TAGS)->assertJsonPath('data.0.tags.*.name', ['laravel']);
    }

    /**
     * Read the given URI twice, asserting the repeat is served from the cache.
     *
     * @param  string  $uri
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function assertCachedRead(string $uri): TestResponse
    {
        $response = $this->getJson($uri)->assertOk();

        self::assertStatementCount(0, $this->recordStatements(fn (): TestResponse => $this->getJson($uri)->assertOk()));

        return $response;
    }

    /**
     * Read the given URI, asserting it is served from the database.
     *
     * @param  string  $uri
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function assertFreshRead(string $uri): TestResponse
    {
        $response = null;

        self::assertNotEmpty($this->recordStatements(function () use ($uri, &$response): void {
            $response = $this->getJson($uri)->assertOk();
        }));

        assert($response instanceof TestResponse);

        return $response;
    }

    /**
     * Read every row through the given cacheable repository as a collection.
     *
     * @param  \Tests\Fixtures\Repositories\CacheablePostRepository|\Tests\Fixtures\Repositories\CacheableUserRepository  $repository
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resource
     * @return \SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection
     */
    private static function collection(CacheablePostRepository|CacheableUserRepository $repository, string $resource): ApiResourceCollection
    {
        /** @var \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model> $rows */
        $rows = $repository->usingResource($resource)->withApiCriteria()->get(); // @phpstan-ignore staticMethod.dynamicCall

        return new ApiResourceCollection(new LengthAwarePaginator($rows, $rows->count(), max($rows->count(), 1)), $resource);
    }
}
