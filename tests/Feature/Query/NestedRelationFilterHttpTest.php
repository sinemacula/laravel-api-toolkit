<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\PublicAuthorPostResource;
use Tests\Fixtures\Resources\PublishedPostsUserResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature test for filtering on a traversable relation column over HTTP.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FilterApplier::class)]
#[CoversClass(QuerySurface::class)]
#[CoversClass(ApiResourceCollection::class)]
final class NestedRelationFilterHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up a users route, the Post resource map, and seeded users and posts.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.resources.resource_map', [
            Post::class => PostResource::class,
        ]);

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/published-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(PublishedPostsUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, PublishedPostsUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/posts', static function (ApiCriteria $criteria): ApiResourceCollection {

            $posts = $criteria->usingResource(PublicAuthorPostResource::class)->apply(new Post)->get();

            return new ApiResourceCollection($posts, PublicAuthorPostResource::class);
        });

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);

        Post::create(['user_id' => $alice->id, 'title' => 'Alice Post', 'body' => 'Content']);
        Post::create(['user_id' => $bob->id, 'title' => 'Bob Post', 'body' => 'Content']);
    }

    /**
     * Test that a filter on a traversable relation column narrows the envelope
     * to the owner.
     *
     * @return void
     */
    public function testRelationColumnFilterNarrowsTheEnvelope(): void
    {
        $filters = json_encode(['posts' => ['title' => ['$eq' => 'Alice Post']]]);

        $response = $this->getJson('/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Alice');
        $response->assertJsonPath('meta.total', 1);
    }

    /**
     * Test that a relation is filtered by the resource declared over it, which
     * withholds a column the related model's canonical resource filters.
     *
     * @return void
     */
    public function testRelationKeyIsGovernedByTheResourceDeclaredOverIt(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);

        $this->getJson('/posts?filters=' . urlencode((string) json_encode(['user' => ['name' => 'Alice']])))
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Alice Post']);

        $response = $this->getJson('/posts?filters=' . urlencode((string) json_encode(['user' => ['email' => ['$eq' => 'alice@example.com']]])));

        $response->assertStatus(422);

        self::assertArrayHasKey('filters.email', (array) $response->json('error.meta'));
    }

    /**
     * Test that a relation method holding the row predicate filters and renders
     * the same rows under the alias it is presented as.
     *
     * @return void
     */
    public function testRelationMethodPredicateGovernsBothTheFilterAndTheRender(): void
    {
        [$alice, $bob] = User::query()->orderBy('id')->get()->all();

        Post::query()->update(['published' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Q3 layoffs', 'body' => 'Draft']);
        Post::create(['user_id' => $bob->id, 'title' => 'Q3 layoffs', 'body' => 'Published', 'published' => true]);

        $filters = json_encode(['publishedPosts' => ['title' => 'Q3 layoffs']]);

        $this->getJson('/published-users?filters=' . urlencode((string) $filters))
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Bob']);

        $this->getJson('/published-users?fields[published_posts_users]=name,posts&fields[posts]=title')
            ->assertOk()
            ->assertJsonPath('data.0.posts.*.title', ['Alice Post'])
            ->assertJsonPath('data.1.posts.*.title', ['Bob Post', 'Q3 layoffs']);
    }
}
