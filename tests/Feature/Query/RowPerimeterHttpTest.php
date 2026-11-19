<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationAggregateOrder;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RowPerimeter;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\ArticleRepository;
use Tests\Fixtures\Repositories\CacheableUserRepository;
use Tests\Fixtures\Repositories\NoteRepository;
use Tests\Fixtures\Repositories\PostRepository;
use Tests\Fixtures\Repositories\TagRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\NoteSubjectResource;
use Tests\Fixtures\Resources\OwnPostResource;
use Tests\Fixtures\Resources\PostOwnerResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\PostTagResource;
use Tests\Fixtures\Resources\PublishedArticleResource;
use Tests\Fixtures\Resources\PublishedPostOwnerResource;
use Tests\Fixtures\Resources\PublishedPostResource;
use Tests\TestCase;

/**
 * Feature tests for a resource's row rule over every path the toolkit reads the
 * resource through.
 *
 * Posts are restricted to the caller's own, so Alice sees her two posts and
 * none of Bob's three, wherever they are listed, embedded, counted, ordered by
 * or filtered on.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RowPerimeter::class)]
#[CoversClass(ApiCriteria::class)]
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(RelationAggregateOrder::class)]
#[CoversClass(ApiResource::class)]
final class RowPerimeterHttpTest extends TestCase
{
    use RecordsStatements, RegistersApiExceptionHandler;

    /** @var \Tests\Fixtures\Models\User */
    private User $alice;

    /** @var \Tests\Fixtures\Models\User */
    private User $bob;

    /** @var array<string, \Tests\Fixtures\Models\Post> */
    private array $posts = [];

    /**
     * Seed two authors, their posts, a tag over every post and notes on two of
     * them, and register a route per path.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.resources.resource_map', [
            Post::class    => OwnPostResource::class,
            User::class    => PostOwnerResource::class,
            Tag::class     => PostTagResource::class,
            Note::class    => NoteSubjectResource::class,
            Article::class => PublishedArticleResource::class,
        ]);

        Config::set(ResourceDiscovery::RESOURCES_KEY, [
            OwnPostResource::class,
            PublishedPostResource::class,
            PostOwnerResource::class,
            PublishedPostOwnerResource::class,
            PostTagResource::class,
            NoteSubjectResource::class,
            PublishedArticleResource::class,
            PostResource::class,
        ]);

        $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $tag = Tag::create(['name' => 'news']);

        $posts = [
            [$this->alice, 'Alice draft', false],
            [$this->alice, 'Alice launch', true],
            [$this->bob, 'Bob plan', true],
            [$this->bob, 'Bob memo', false],
            [$this->bob, 'Bob notes', false],
        ];

        foreach ($posts as [$author, $title, $published]) {
            $this->posts[$title] = Post::create(['user_id' => $author->id, 'title' => $title, 'body' => 'Body', 'published' => $published]);

            $tag->posts()->attach($this->posts[$title]);
        }

        $this->attachNote($this->posts['Alice launch'], 'On launch');
        $this->attachNote($this->posts['Bob plan'], 'On plan');

        $this->registerRoutes();
    }

    /**
     * Test that the root lists only the visible rows and totals only them.
     *
     * @return void
     */
    public function testRootListsAndTotalsOnlyVisibleRows(): void
    {
        $response = $this->actingAs($this->alice)->getJson('/posts');

        $response->assertOk();
        $response->assertJsonPath('data.*.title', ['Alice draft', 'Alice launch']);
        $response->assertJsonPath('meta.total', 2);
    }

    /**
     * Test that a record the caller cannot see is not found.
     *
     * @return void
     */
    public function testInvisibleRecordIsNotFound(): void
    {
        $this->actingAs($this->alice)->getJson('/posts/' . $this->posts['Alice launch']->id)->assertOk();
        $this->actingAs($this->alice)->getJson('/posts/' . $this->posts['Bob plan']->id)->assertNotFound();
    }

    /**
     * Test that a bulk read through the filters alone sees only the visible
     * rows.
     *
     * @return void
     */
    public function testFiltersOnlyReadSeesOnlyVisibleRows(): void
    {
        $this->actingAs($this->alice)->getJson('/posts/count')->assertOk()->assertExactJson(['count' => 2]);
    }

    /**
     * Test that an embedded relation renders only the visible rows on every
     * item, through the criteria and through a resource loading what is
     * missing.
     *
     * @return void
     */
    public function testEmbeddedRelationRendersOnlyVisibleRows(): void
    {
        $response = $this->actingAs($this->alice)->getJson('/owners?fields[post_owners]=name,posts');

        $response->assertOk();
        $response->assertJsonPath('data.0.posts.*.title', ['Alice draft', 'Alice launch']);
        $response->assertJsonPath('data.1.posts', []);

        $this->actingAs($this->alice)->getJson('/owners/' . $this->bob->id . '?fields[post_owners]=name,posts')->assertOk()->assertJsonPath('data.posts', []);
    }

    /**
     * Test that counts and sums aggregate only the visible rows, through the
     * criteria and through a resource loading what is missing.
     *
     * @return void
     */
    public function testAggregatesCountOnlyVisibleRows(): void
    {
        $expected = ['posts_count' => 2, 'posts_sum_id' => $this->posts['Alice draft']->id + $this->posts['Alice launch']->id];
        $query    = '?fields[post_owners]=name,aggregates&aggregates[post_owners]=posts_count,posts_sum_id';

        $response = $this->actingAs($this->alice)->getJson('/owners' . $query);

        $response->assertOk();
        self::assertEquals($expected, $response->json('data.0.aggregates'));
        self::assertEquals(['posts_count' => 0, 'posts_sum_id' => null], $response->json('data.1.aggregates'));

        self::assertEquals($expected, $this->actingAs($this->alice)->getJson('/owners/' . $this->alice->id . $query)->assertOk()->json('data.aggregates'));
    }

    /**
     * Test that an order by a relation aggregate counts only the visible rows.
     *
     * @return void
     */
    public function testAggregateOrderCountsOnlyVisibleRows(): void
    {
        $this->actingAs($this->alice)->getJson('/owners?order=aggregates.posts_count:desc')->assertOk()->assertJsonPath('data.*.name', ['Alice', 'Bob']);
    }

    /**
     * Test that a relation filter matches only the visible rows.
     *
     * @return void
     */
    public function testRelationFilterMatchesOnlyVisibleRows(): void
    {
        $visible   = urlencode('{"posts":{"title":{"$eq":"Alice launch"}}}');
        $invisible = urlencode('{"posts":{"title":{"$eq":"Bob plan"}}}');

        $this->actingAs($this->alice)->getJson('/owners?filters=' . $visible)->assertOk()->assertJsonPath('data.*.name', ['Alice']);
        $this->actingAs($this->alice)->getJson('/owners?filters=' . $invisible)->assertOk()->assertJsonPath('data', []);
    }

    /**
     * Test that has and hasnt follow the visible rows.
     *
     * @return void
     */
    public function testHasAndHasntFollowVisibleRows(): void
    {
        $this->actingAs($this->alice)->getJson('/owners?filters=' . urlencode('{"$has":["posts"]}'))->assertOk()->assertJsonPath('data.*.name', ['Alice']);
        $this->actingAs($this->alice)->getJson('/owners?filters=' . urlencode('{"$hasnt":["posts"]}'))->assertOk()->assertJsonPath('data.*.name', ['Bob']);
    }

    /**
     * Test that the resource a parent declares over a relation governs it, and
     * a relation it declares none over follows the mapped resource.
     *
     * @return void
     */
    public function testDeclaredResourceGovernsItsRelationOverTheMappedOne(): void
    {
        $query    = 'fields[published_post_owners]=name,posts,aggregates&aggregates[published_post_owners]=posts_count,published_posts_count';
        $response = $this->actingAs($this->alice)->getJson('/published-owners?' . $query);

        $response->assertOk();
        $response->assertJsonPath('data.0.posts.*.title', ['Alice launch']);
        $response->assertJsonPath('data.0.aggregates', ['posts_count' => 1, 'published_posts_count' => 1]);
        $response->assertJsonPath('data.1.posts.*.title', ['Bob plan']);
        $response->assertJsonPath('data.1.aggregates', ['posts_count' => 1, 'published_posts_count' => 0]);
    }

    /**
     * Test that a many-to-many relation embeds only the visible rows.
     *
     * @return void
     */
    public function testManyToManyRelationEmbedsOnlyVisibleRows(): void
    {
        $this->actingAs($this->alice)->getJson('/tags?fields[post_tags]=name,posts')->assertOk()->assertJsonPath('data.0.posts.*.title', ['Alice draft', 'Alice launch']);
    }

    /**
     * Test that an aggregate an embedded resource loads counts only the visible
     * rows, beneath a relation that is not itself restricted.
     *
     * @return void
     */
    public function testEmbeddedResourceAggregateCountsOnlyVisibleRows(): void
    {
        $response = $this->actingAs($this->alice)->getJson('/posts?fields[own_posts]=title,tags&fields[post_tags]=name,aggregates&aggregates[post_tags]=posts_count');

        $response->assertOk();
        $response->assertJsonPath('data.*.tags.*.aggregates.posts_count', [2, 2]);
    }

    /**
     * Test that a polymorphic relation to a row the caller cannot see loads
     * nothing.
     *
     * @return void
     */
    public function testPolymorphicRelationToAnInvisibleRowLoadsNothing(): void
    {
        $response = $this->actingAs($this->alice)->getJson('/notes');

        $response->assertOk();
        $response->assertJsonPath('data.0.subject', $this->posts['Alice launch']->id);
        $response->assertJsonPath('data.1.subject', null);
    }

    /**
     * Test that a relation back to the resource's own model applies the rule to
     * its own alias when embedded, counted and filtered on.
     *
     * @return void
     */
    public function testSelfRelationAppliesTheRuleToItsAlias(): void
    {
        foreach (['published', 'draft'] as $status) {
            Article::create(['user_id' => $this->alice->id, 'title' => ucfirst($status), 'slug' => $status, 'body' => 'Body', 'summary' => 'Summary', 'status' => $status]);
        }

        $query    = 'fields[published_articles]=title,siblingArticles,aggregates&aggregates[published_articles]=sibling_articles_count';
        $response = $this->getJson('/articles?' . $query . '&filters=' . urlencode('{"$has":["siblingArticles"]}'));

        $response->assertOk();
        $response->assertJsonPath('data.*.title', ['Published']);
        $response->assertJsonPath('data.0.siblingArticles.*.title', ['Published']);
        $response->assertJsonPath('data.0.aggregates.sibling_articles_count', 1);
    }

    /**
     * Test that without a caller every path sees nothing.
     *
     * @return void
     */
    public function testEveryPathSeesNothingWithoutACaller(): void
    {
        $this->getJson('/posts')->assertOk()->assertJsonPath('data', []);

        $response = $this->getJson('/owners?fields[post_owners]=name,posts,aggregates&aggregates[post_owners]=posts_count');

        $response->assertOk();
        $response->assertJsonPath('data.*.posts', [[], []]);
        $response->assertJsonPath('data.*.aggregates.posts_count', [0, 0]);

        $this->getJson('/owners?filters=' . urlencode('{"$has":["posts"]}'))->assertOk()->assertJsonPath('data', []);
    }

    /**
     * Test that a resource that does not restrict its rows adds nothing to the
     * queries it governs.
     *
     * @return void
     */
    public function testUnrestrictedResourceAddsNothingToItsQueries(): void
    {
        Config::set('api-toolkit.resources.resource_map', [Post::class => PostResource::class, Note::class => NoteSubjectResource::class]);

        $subjects   = [$this->posts['Alice launch']->id, $this->posts['Bob plan']->id];
        $statements = $this->recordStatements(fn () => $this->getJson('/notes')->assertOk()->assertJsonPath('data.*.subject', $subjects));

        self::assertStringNotContainsString('user_id', implode("\n", $statements));
    }

    /**
     * Test that a cacheable read caches each caller's visible rows apart, and
     * still caches a read no restricting resource reaches.
     *
     * @return void
     */
    public function testCacheableReadIsCachedPerCaller(): void
    {
        Config::set('cache.default', 'array');

        $url = '/cached-owners?fields[post_owners]=name,posts';

        self::assertSame(['Alice draft', 'Alice launch'], $this->actingAs($this->alice)->getJson($url)->assertOk()->json('data.0.posts.*.title'));
        self::assertSame(['Bob plan', 'Bob memo', 'Bob notes'], $this->actingAs($this->bob)->getJson($url)->assertOk()->json('data.1.posts.*.title'));

        $this->getJson('/cached-owners?fields[post_owners]=name')->assertOk();

        DB::enableQueryLog();

        self::assertSame([], $this->actingAs($this->alice)->getJson($url)->assertOk()->json('data.1.posts'));

        $this->getJson('/cached-owners?fields[post_owners]=name')->assertOk();

        self::assertCount(0, DB::getQueryLog());

        DB::disableQueryLog();
    }

    /**
     * Register a route per path the posts are read through.
     *
     * @return void
     */
    private function registerRoutes(): void
    {
        Route::middleware(ParseApiQuery::class)->group(function (): void {

            Route::get('/posts', static fn (PostRepository $posts): ApiResourceCollection => self::collection($posts, OwnPostResource::class));

            Route::get('/posts/count', static fn (PostRepository $posts): array => [
                'count' => $posts->usingResource(OwnPostResource::class)->withApiFilters()->count(), // @phpstan-ignore staticMethod.dynamicCall
            ]);

            Route::get('/posts/{post}', static function (PostRepository $posts, int $post): OwnPostResource {

                $record = $posts->usingResource(OwnPostResource::class)->withApiCriteria()->scopeById($post)->firstOrFail(); // @phpstan-ignore staticMethod.dynamicCall

                return new OwnPostResource($record);
            });

            Route::get('/owners', static fn (UserRepository $users): ApiResourceCollection => self::collection($users, PostOwnerResource::class));

            Route::get('/owners/{owner}', static fn (int $owner): PostOwnerResource => new PostOwnerResource(User::query()->findOrFail($owner), loadMissing: true));

            Route::get('/published-owners', static fn (UserRepository $users): ApiResourceCollection => self::collection($users, PublishedPostOwnerResource::class));

            Route::get('/tags', static fn (TagRepository $tags): ApiResourceCollection => self::collection($tags, PostTagResource::class));

            Route::get('/notes', static fn (NoteRepository $notes): ApiResourceCollection => self::collection($notes, NoteSubjectResource::class));

            Route::get('/articles', static fn (ArticleRepository $articles): ApiResourceCollection => self::collection($articles, PublishedArticleResource::class));

            Route::get('/cached-owners', static function (CacheableUserRepository $users): ApiResourceCollection {

                $owners = $users->usingResource(PostOwnerResource::class)->withApiCriteria()->get(); // @phpstan-ignore staticMethod.dynamicCall

                return new ApiResourceCollection($owners, PostOwnerResource::class);
            });
        });
    }

    /**
     * Read a page of the repository's records through the resource.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\ApiRepository<covariant \Illuminate\Database\Eloquent\Model>  $repository
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resource
     * @return \SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection
     *
     * @throws \Throwable
     */
    private static function collection(ApiRepository $repository, string $resource): ApiResourceCollection
    {
        return new ApiResourceCollection($repository->usingResource($resource)->withApiCriteria()->paginate(), $resource);
    }

    /**
     * Attach a note with the given title to the model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $noteable
     * @param  string  $title
     * @return void
     */
    private function attachNote(Model $noteable, string $title): void
    {
        $note        = new Note;
        $note->title = $title;

        $note->noteable()->associate($noteable)->save();
    }
}
