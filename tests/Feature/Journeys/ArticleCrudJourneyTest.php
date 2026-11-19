<?php

declare(strict_types = 1);

namespace Tests\Feature\Journeys;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ProvidesApiEnvelope;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use SineMacula\ApiToolkit\Http\Routing\Controller;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Actors\ActorUser;
use Tests\Fixtures\Controllers\ArticleController;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Policies\ArticlePolicy;
use Tests\Fixtures\Resources\TaggedArticleResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests walking an article through create, read, update and delete on a
 * resource controller, as a consuming application routes and writes one.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Controller::class)]
#[CoversClass(AuthorizedController::class)]
#[CoversClass(ApiRepository::class)]
#[CoversTrait(ProvidesApiEnvelope::class)]
final class ArticleCrudJourneyTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var string The field selection that renders an article's relations and tag count */
    private const string FULL_SELECTION = 'fields[tagged_articles]=title,summary,status,author,tags,aggregates&aggregates[tagged_articles]=tags_count'
        . '&fields[users]=name&fields[tags]=name';

    /** @var \Tests\Fixtures\Actors\ActorUser The author of the articles */
    private ActorUser $author;

    /** @var array<string, int> The key of each tag, by name */
    private array $tags = [];

    /**
     * Route the articles resource controller and seed the author and tags.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [TaggedArticleResource::class, UserResource::class, TagResource::class]);
        Config::set('api-toolkit.resources.resource_map', [Article::class => TaggedArticleResource::class]);

        if (DB::connection()->getDriverName() === 'sqlite') {
            Config::set('api-toolkit.search.unverified_connections', [DB::getDefaultConnection()]);
        }

        Gate::policy(Article::class, ArticlePolicy::class);

        Route::apiResource('articles', ArticleController::class)
            ->middleware([ParseApiQuery::class, 'auth', SubstituteBindings::class]);

        $this->author = ActorUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        foreach (['news', 'tech', 'ops'] as $name) {
            $this->tags[$name] = Tag::create(['name' => $name])->id;
        }
    }

    /**
     * Test that an article is created, read, listed, replaced, patched, deleted
     * and then found only among the trashed.
     *
     * @return void
     */
    public function testAnArticleCompletesItsLifecycleThroughTheController(): void
    {
        $this->actingAs($this->author);

        $id = $this->postJson('/articles', $this->storeBody(['tags' => [$this->tags['news']]]))->assertCreated()->json('data.id');

        self::assertIsInt($id);

        $this->getJson('/articles/' . $id . '?' . self::FULL_SELECTION)
            ->assertOk()
            ->assertJsonPath('data.author.name', 'Alice')
            ->assertJsonPath('data.tags.0.name', 'news')
            ->assertJsonPath('data.aggregates.tags_count', 1);

        $this->getJson('/articles?filters={"status":{"$eq":"published"}}')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertHeader('Total-Count', '1');

        $this->putJson('/articles/' . $id, ['title' => 'Replaced', 'body' => 'New body', 'summary' => 'New summary', 'status' => 'draft', 'tags' => [$this->tags['ops']]])
            ->assertOk()
            ->assertJsonPath('data.title', 'Replaced');

        $this->patchJson('/articles/' . $id, ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Replaced')
            ->assertJsonPath('data.status', 'published');

        self::assertSame([$this->tags['ops']], $this->tagKeysOf($id));

        $this->deleteJson('/articles/' . $id)->assertNoContent();
        $this->getJson('/articles/' . $id)->assertNotFound();

        $this->getJson('/articles')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/articles?trashed=only')->assertOk()->assertJsonPath('data.0.id', $id);
    }

    /**
     * Test that an invalid payload is refused with every failing field named
     * and nothing written.
     *
     * @return void
     */
    public function testStoreRefusesAnInvalidPayloadWithoutWriting(): void
    {
        $response = $this->actingAs($this->author)->postJson('/articles', ['slug' => 'draft', 'body' => 'Body', 'summary' => 'Summary', 'tags' => [999]]);

        $response->assertUnprocessable();
        $response->assertJsonPath('error.code', 10106);
        self::assertSame(['title', 'tags.0'], array_keys((array) $response->json('error.meta')));
        self::assertSame(0, Article::withTrashed()->count());
    }

    /**
     * Test that a valid payload creates the article by the current user with
     * its tags synced, and answers 201 with the created representation.
     *
     * @return void
     */
    public function testStoreCreatesTheArticleWithItsTagsAndAnswersCreated(): void
    {
        $response = $this->actingAs($this->author)->postJson('/articles?' . self::FULL_SELECTION, $this->storeBody(['tags' => [$this->tags['news'], $this->tags['tech']]]));

        $response->assertCreated();
        $response->assertJsonPath('data._type', 'tagged_articles');
        $response->assertJsonPath('data.author.name', 'Alice');
        $response->assertJsonPath('data.tags.*.name', ['news', 'tech']);
        $response->assertJsonPath('data.aggregates.tags_count', 2);

        $article = Article::query()->sole();

        self::assertSame($this->author->id, $article->user_id);
        self::assertSame('published', $article->status);
        self::assertSame([$this->tags['news'], $this->tags['tech']], $this->tagKeysOf($article->id));
    }

    /**
     * Test that an omitted defaulted field takes the column default rather than
     * a value the client never sent.
     *
     * @return void
     */
    public function testStoreLeavesAnOmittedStatusToTheColumnDefault(): void
    {
        $body = $this->storeBody();

        unset($body['status']);

        $this->actingAs($this->author)->postJson('/articles', $body)->assertCreated()->assertJsonPath('data.status', 'draft');
    }

    /**
     * Test that showing a route-bound article loads the requested fields,
     * relations and aggregates.
     *
     * @return void
     */
    public function testShowLoadsTheRequestedRelationsAndAggregatesOnTheBoundModel(): void
    {
        $article = $this->article('first', tags: ['news', 'ops']);

        $response = $this->actingAs($this->author)->getJson('/articles/' . $article->id . '?' . self::FULL_SELECTION);

        $response->assertOk();
        $response->assertExactJson(['data' => [
            '_type'   => 'tagged_articles',
            'id'      => $article->id,
            'title'   => 'Title first',
            'summary' => 'Summary first',
            'status'  => 'published',
            'author'  => ['_type' => 'users', 'id' => $this->author->id, 'name' => 'Alice'],
            'tags'    => [
                ['_type' => 'tags', 'id' => $this->tags['news'], 'name' => 'news'],
                ['_type' => 'tags', 'id' => $this->tags['ops'], 'name' => 'ops'],
            ],
            'aggregates' => ['tags_count' => 2],
        ]]);
    }

    /**
     * Test that the index filters, orders and searches the collection and
     * renders the offset page envelope with its total.
     *
     * @return void
     */
    public function testIndexFiltersOrdersAndSearchesAnOffsetPage(): void
    {
        $this->article('low', views: 1);
        $this->article('high', views: 9);
        $this->article('middle', views: 5);
        $this->article('hidden', status: 'draft', views: 7);

        $this->actingAs($this->author);

        $page = $this->getJson('/articles?filters={"status":{"$eq":"published"},"author":{"name":{"$eq":"Alice"}}}&order=views:desc&limit=2');

        $page->assertOk();
        $page->assertHeader('Total-Count', '3');
        $page->assertJsonPath('data.*.slug', ['high', 'middle']);
        $page->assertJsonPath('meta', ['total' => 3, 'total_capped' => false, 'count' => 2, 'continue' => true]);
        $page->assertJsonPath('links.next', static fn (mixed $link): bool => is_string($link) && preg_match('~^http://localhost/articles\?.*\bpage=2\b~', $link) === 1);

        $this->getJson('/articles?search=middle')->assertOk()->assertJsonPath('data.*.slug', ['middle']);
    }

    /**
     * Test that a cursor walk through the index visits every article once and
     * carries no total.
     *
     * @return void
     */
    public function testIndexWalksCursorPagesWithoutATotal(): void
    {
        foreach (['a', 'b', 'c'] as $slug) {
            $this->article($slug);
        }

        $this->actingAs($this->author);

        $slugs = [];
        $url   = '/articles?pagination=cursor&limit=2';

        while (is_string($url)) {

            $page = $this->getJson($url)->assertOk()->assertHeaderMissing('Total-Count');

            self::assertSame(['continue'], array_keys((array) $page->json('meta')));

            $slugs = [...$slugs, ...(array) $page->json('data.*.slug')];
            $url   = $page->json('links.next');
        }

        self::assertSame(['a', 'b', 'c'], $slugs);
    }

    /**
     * Test that PUT replaces every field it sends and syncs the tags to exactly
     * the sent set.
     *
     * @return void
     */
    public function testPutReplacesTheSentFieldsAndSyncsTheTags(): void
    {
        $article = $this->article('first', tags: ['news', 'tech']);

        $response = $this->actingAs($this->author)->putJson('/articles/' . $article->id . '?' . self::FULL_SELECTION, [
            'title'   => 'Replaced',
            'body'    => 'Replaced body',
            'summary' => 'Replaced summary',
            'status'  => 'draft',
            'tags'    => [$this->tags['ops']],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.title', 'Replaced');
        $response->assertJsonPath('data.tags.*.name', ['ops']);
        $response->assertJsonPath('data.aggregates.tags_count', 1);
        self::assertSame([$this->tags['ops']], $this->tagKeysOf($article->id));
    }

    /**
     * Test that PATCH writes only the fields it sends, leaving every other
     * column and the tags as they were.
     *
     * @return void
     */
    public function testPatchKeepsEveryFieldItDoesNotSend(): void
    {
        $article = $this->article('first', tags: ['news']);

        $this->actingAs($this->author)->patchJson('/articles/' . $article->id, ['summary' => 'Patched'])->assertOk();

        $fresh = Article::query()->findOrFail($article->id);

        self::assertSame(['Title first', 'first', 'Body first', 'Patched', 'published'], [$fresh->title, $fresh->slug, $fresh->body, $fresh->summary, $fresh->status]);
        self::assertSame([$this->tags['news']], $this->tagKeysOf($article->id));
    }

    /**
     * Test that DELETE soft deletes the article, answers 204 with no body, and
     * the article is no longer found.
     *
     * @return void
     */
    public function testDestroySoftDeletesAndTheArticleIsNoLongerFound(): void
    {
        $article = $this->article('first');

        $this->actingAs($this->author)->deleteJson('/articles/' . $article->id)->assertNoContent();

        self::assertTrue(Article::withTrashed()->findOrFail($article->id)->trashed());

        $this->getJson('/articles/' . $article->id)->assertNotFound()->assertJsonPath('error.code', 10103);
    }

    /**
     * Provide each trashed state with the slugs the index then lists.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function trashedStates(): iterable
    {
        yield 'live only' => ['', ['live']];
        yield 'with trashed' => ['?trashed=with', ['live', 'deleted']];
        yield 'only trashed' => ['?trashed=only', ['deleted']];
    }

    /**
     * Test that the trashed parameter widens the index to the deleted articles.
     *
     * @param  string  $query
     * @param  list<string>  $slugs
     * @return void
     */
    #[DataProvider('trashedStates')]
    public function testTrashedParameterWidensTheIndex(string $query, array $slugs): void
    {
        $this->article('live');
        $this->article('deleted')->delete();

        $this->actingAs($this->author)->getJson('/articles' . $query)->assertOk()->assertJsonPath('data.*.slug', $slugs);
    }

    /**
     * Provide each action with the request the policy refuses.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedActions(): iterable
    {
        yield 'index by a suspended actor' => ['suspended', 'GET', '/articles'];
        yield 'store by a suspended actor' => ['suspended', 'POST', '/articles'];
        yield 'show of a draft by another actor' => ['other', 'GET', '/articles/{draft}'];
        yield 'update by another actor' => ['other', 'PUT', '/articles/{published}'];
        yield 'patch by another actor' => ['other', 'PATCH', '/articles/{published}'];
        yield 'destroy by another actor' => ['other', 'DELETE', '/articles/{published}'];
    }

    /**
     * Test that the policy refuses each action with the forbidden envelope
     * before the action writes anything.
     *
     * @param  string  $actor
     * @param  string  $method
     * @param  string  $uri
     * @return void
     */
    #[DataProvider('refusedActions')]
    public function testThePolicyRefusesTheActionBeforeItRuns(string $actor, string $method, string $uri): void
    {
        $draft     = $this->article('draft', status: 'draft');
        $published = $this->article('published');
        $before    = DB::table('articles')->orderBy('id')->get()->toArray();

        $acting = ActorUser::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => $actor === 'suspended' ? 'suspended' : 'active']);
        $uri    = strtr($uri, ['{draft}' => (string) $draft->id, '{published}' => (string) $published->id]);

        $response = $this->actingAs($acting)->json($method, $uri, $this->storeBody(['slug' => 'refused']));

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 10102);
        self::assertEquals($before, DB::table('articles')->orderBy('id')->get()->toArray());
    }

    /**
     * Test that a guest is refused as unauthenticated before any policy runs.
     *
     * @return void
     */
    public function testAGuestIsUnauthenticated(): void
    {
        $this->getJson('/articles')->assertUnauthorized()->assertJsonPath('error.code', 10101);
    }

    /**
     * Build a valid store body, overridden by the given fields.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function storeBody(array $overrides = []): array
    {
        return [...['title' => 'Launch', 'slug' => 'launch', 'body' => 'Body', 'summary' => 'Summary', 'status' => 'published'], ...$overrides];
    }

    /**
     * Create an article by the author with the given tags.
     *
     * @param  string  $slug
     * @param  string  $status
     * @param  int  $views
     * @param  list<string>  $tags
     * @return \Tests\Fixtures\Models\Article
     */
    private function article(string $slug, string $status = 'published', int $views = 0, array $tags = []): Article
    {
        $article = Article::create([
            'user_id' => $this->author->id,
            'title'   => 'Title ' . $slug,
            'slug'    => $slug,
            'body'    => 'Body ' . $slug,
            'summary' => 'Summary ' . $slug,
            'status'  => $status,
            'views'   => $views,
        ]);

        $article->tags()->attach(array_map(fn (string $name): int => $this->tags[$name], $tags));

        return $article;
    }

    /**
     * Return the keys of the tags attached to the given article, ascending.
     *
     * @param  int  $article
     * @return list<int>
     */
    private function tagKeysOf(int $article): array
    {
        $keys = DB::table('article_tag')->where('article_id', $article)->orderBy('tag_id')->pluck('tag_id')->all();

        return array_values(array_map(static fn (mixed $key): int => (int) filter_var($key, FILTER_VALIDATE_INT), $keys));
    }
}
