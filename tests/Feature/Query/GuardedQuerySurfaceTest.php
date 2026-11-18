<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\CallerAdmission;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\GuardedQueryablePostResource;
use Tests\Fixtures\Resources\GuardedQueryableUserResource;
use Tests\Fixtures\Resources\PublicAuthorPostResource;
use Tests\Fixtures\Resources\PublicUserResource;
use Tests\TestCase;

/**
 * Feature tests for keys a caller guard hides, refused over HTTP exactly as an
 * undeclared key is.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(QuerySurface::class)]
#[CoversClass(CallerAdmission::class)]
final class GuardedQuerySurfaceTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up guarded and undeclaring routes over seeded users and posts.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        $this->registerUserRoute('/guarded-users', GuardedQueryableUserResource::class);
        $this->registerUserRoute('/public-users', PublicUserResource::class);
        $this->registerPostRoute('/guarded-posts', GuardedQueryablePostResource::class);
        $this->registerPostRoute('/public-posts', PublicAuthorPostResource::class);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        User::create(['name' => 'Admin', 'email' => 'admin@example.com']);

        Post::create(['user_id' => $alice->id, 'title' => 'Hidden draft', 'body' => 'Content']);
        Post::create(['user_id' => $bob->id, 'title' => 'Bob Post', 'body' => 'Content']);
    }

    /**
     * Provide each hidden key request beside the same request to a resource
     * that never declares the key.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function hiddenKeyRequests(): iterable
    {
        $filters = static fn (array $filters): string => '?filters=' . urlencode((string) json_encode($filters));

        $users = [
            'plain filter'           => $filters(['email' => 'alice@example.com']),
            'operator filter'        => $filters(['email' => ['$eq' => 'alice@example.com']]),
            'or group'               => $filters(['$or' => [['name' => 'Bob'], ['email' => 'alice@example.com']]]),
            'and group'              => $filters(['$and' => [['name' => 'Alice'], ['email' => ['$in' => ['alice@example.com']]]]]),
            'order'                  => '?order=email',
            'has'                    => $filters(['$has' => 'posts']),
            'hasnt'                  => $filters(['$hasnt' => ['posts']]),
            'nested relation filter' => $filters(['posts' => ['title' => 'Hidden draft']]),
        ];

        foreach ($users as $label => $query) {
            yield $label => ['/guarded-users' . $query, '/public-users' . $query];
        }

        $related = $filters(['user' => ['email' => 'alice@example.com']]);

        yield 'related level filter' => ['/guarded-posts' . $related, '/public-posts' . $related];
    }

    /**
     * Test that a caller the guard hides a key from gets the body an undeclared
     * key gets, through every route a key reaches the surface by.
     *
     * @param  string  $guarded
     * @param  string  $undeclared
     * @return void
     */
    #[DataProvider('hiddenKeyRequests')]
    public function testAHiddenKeyIsRefusedExactlyAsAnUndeclaredOne(string $guarded, string $undeclared): void
    {
        $hidden   = $this->getJson($guarded);
        $expected = $this->getJson($undeclared);

        $expected->assertStatus(422);
        $hidden->assertStatus(422);

        self::assertSame($expected->json(), $hidden->json());
    }

    /**
     * Test that a caller the guard passes gets every hidden key applied.
     *
     * @return void
     */
    public function testAPassingCallerQueriesTheGuardedKeys(): void
    {
        $this->actingAs(User::query()->where('email', 'admin@example.com')->firstOrFail());

        $this->getJson('/guarded-users?filters=' . urlencode((string) json_encode(['email' => 'alice@example.com'])))
            ->assertOk()
            ->assertJsonPath('data.*.email', ['alice@example.com']);

        $this->getJson('/guarded-users?order=email:desc')
            ->assertOk()
            ->assertJsonPath('data.*.email', ['bob@example.com', 'alice@example.com', 'admin@example.com']);

        $this->getJson('/guarded-users?filters=' . urlencode((string) json_encode(['posts' => ['title' => 'Hidden draft']])))
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Alice']);

        $this->getJson('/guarded-posts?filters=' . urlencode((string) json_encode(['user' => ['email' => 'bob@example.com']])))
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Bob Post']);
    }

    /**
     * Test that one process serving a passing caller then a hidden one shares
     * the compiled schema but never the admission.
     *
     * @return void
     */
    public function testAdmissionIsNeverCarriedToTheNextRequest(): void
    {
        $query = '/guarded-users?filters=' . urlencode((string) json_encode(['email' => 'alice@example.com']));

        $this->actingAs(User::query()->where('email', 'admin@example.com')->firstOrFail());
        $this->getJson($query)->assertOk();

        $compiled = SchemaCompiler::compile(GuardedQueryableUserResource::class);

        Auth::forgetGuards();

        $this->getJson($query)->assertStatus(422)->assertJsonPath('error.meta', [
            'filters.email' => ['The "email" key is not a permitted query parameter for this resource.'],
        ]);

        self::assertSame($compiled, SchemaCompiler::compile(GuardedQueryableUserResource::class));
    }

    /**
     * Register a users route reading through the given resource.
     *
     * @param  string  $uri
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resource
     * @return void
     */
    private function registerUserRoute(string $uri, string $resource): void
    {
        Route::middleware(ParseApiQuery::class)->get($uri, static function (UserRepository $repository) use ($resource): ApiResourceCollection {

            $users = $repository->usingResource($resource)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, $resource);
        });
    }

    /**
     * Register a posts route reading through the given resource.
     *
     * @param  string  $uri
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resource
     * @return void
     */
    private function registerPostRoute(string $uri, string $resource): void
    {
        Route::middleware(ParseApiQuery::class)->get($uri, static function (ApiCriteria $criteria) use ($resource): ApiResourceCollection {

            $posts = $criteria->usingResource($resource)->apply(new Post)->get();

            return new ApiResourceCollection($posts, $resource);
        });
    }
}
