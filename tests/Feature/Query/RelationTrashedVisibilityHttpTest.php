<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SoftDeleteVisibilityApplier;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Comment;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\ArticleRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\AuthorResource;
use Tests\Fixtures\Resources\CommentResource;
use Tests\Fixtures\Resources\TrashedCascadeArticleResource;
use Tests\TestCase;

/**
 * Feature tests for trashed visibility cascading to eager-loaded relations over
 * HTTP.
 *
 * The root is a soft-deleting article that opts in, with sibling articles that
 * opt in and author comments that do not, so one honoured request proves the
 * gate is per relation. A relation's aggregates and filters see the rows it
 * embeds. A root that cannot honour the request refuses it rather than widening
 * only its children.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RelationTrashedGate::class)]
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(EagerLoadPlanner::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(SoftDeleteVisibilityApplier::class)]
#[CoversClass(ApiCriteria::class)]
final class RelationTrashedVisibilityHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up an articles route over soft-deleting relations holding live and
     * trashed rows, and an authors route whose root never soft deletes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.resources.resource_map', [
            Article::class => ArticleResource::class,
            Comment::class => CommentResource::class,
        ]);

        Config::set(ResourceDiscovery::RESOURCES_KEY, [
            TrashedCascadeArticleResource::class,
            ArticleResource::class,
            AuthorResource::class,
            CommentResource::class,
        ]);

        $author = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret', 'status' => 'active']);

        $this->seedArticle($author->id, 'First Article', 'first-article');
        $this->seedArticle($author->id, 'Second Article', 'second-article');
        $this->seedArticle($author->id, 'Removed Article', 'removed-article')->delete();

        Comment::create(['user_id' => $author->id, 'body' => 'Live one']);
        Comment::create(['user_id' => $author->id, 'body' => 'Live two']);
        Comment::create(['user_id' => $author->id, 'body' => 'Removed'])->delete();

        Route::middleware(ParseApiQuery::class)->get('/articles', function (ArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->usingResource(TrashedCascadeArticleResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($articles, TrashedCascadeArticleResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/authors', function (UserRepository $repository): ApiResourceCollection {

            $authors = $repository->usingResource(AuthorResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($authors, AuthorResource::class);
        });
    }

    /**
     * Tear down each test, clearing the static schema and map caches.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that without a trashed parameter the root and both relations return
     * only live rows.
     *
     * @return void
     */
    public function testDefaultReturnsOnlyLiveRows(): void
    {
        $response = $this->getJson('/articles');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonCount(2, 'data.0.siblingArticles');
        $response->assertJsonCount(2, 'data.0.authorComments');
    }

    /**
     * Test that trashed=with widens the root and the opted-in relation while
     * the closed relation stays live.
     *
     * @return void
     */
    public function testWithWidensTheRootAndOnlyTheOptedInRelation(): void
    {
        $response = $this->getJson('/articles?trashed=with');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonCount(3, 'data.0.siblingArticles');
        $response->assertJsonCount(2, 'data.0.authorComments');
    }

    /**
     * Test that trashed=only narrows the root and the opted-in relation to
     * trashed rows while the closed relation stays live.
     *
     * @return void
     */
    public function testOnlyNarrowsTheRootAndOnlyTheOptedInRelation(): void
    {
        $response = $this->getJson('/articles?trashed=only');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Removed Article');
        $response->assertJsonCount(1, 'data.0.siblingArticles');
        $response->assertJsonPath('data.0.siblingArticles.0.title', 'Removed Article');
        $response->assertJsonCount(2, 'data.0.authorComments');
    }

    /**
     * Provide each trashed state with the sibling articles it embeds.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function trashedStateProvider(): iterable
    {
        yield 'live only' => ['', 2];
        yield 'with trashed' => ['&trashed=with', 3];
        yield 'only trashed' => ['&trashed=only', 1];
    }

    /**
     * Test that each relation's count matches the rows it embeds, whatever the
     * trashed state.
     *
     * @param  string  $query
     * @param  int  $articles
     * @return void
     */
    #[DataProvider('trashedStateProvider')]
    public function testRelationAggregatesCountTheRowsTheRelationEmbeds(string $query, int $articles): void
    {
        $response = $this->getJson('/articles?fields[trashed_cascade_articles]=title,siblingArticles,authorComments,aggregates' . $query);

        $response->assertOk();

        foreach ((array) $response->json('data') as $article) {

            self::assertIsArray($article);
            self::assertCount($articles, (array) $article['siblingArticles']);
            self::assertCount(2, (array) $article['authorComments']);
            self::assertSame(['sibling_articles_count' => $articles, 'author_comments_count' => 2], $article['aggregates']);
        }
    }

    /**
     * Test that an embedded resource's aggregates follow the trashed state of
     * each relation they count.
     *
     * @param  string  $query
     * @param  int  $articles
     * @return void
     */
    #[DataProvider('trashedStateProvider')]
    public function testEmbeddedResourceAggregatesFollowTheTrashedState(string $query, int $articles): void
    {
        $response = $this->getJson('/articles?fields[trashed_cascade_articles]=title,author&fields[authors]=name,aggregates' . $query);

        $response->assertOk();
        $response->assertJsonPath('data.0.author.aggregates', ['articles_count' => $articles, 'comments_count' => 2]);
    }

    /**
     * Provide relation filters with the article titles each matches.
     *
     * @return iterable<string, array{string, array<int, string>}>
     */
    public static function relationFilterProvider(): iterable
    {
        yield 'has under live only' => [
            'filters=' . urlencode('{"$has":["siblingArticles"]}'),
            ['First Article', 'Second Article'],
        ];

        yield 'has under with trashed' => [
            'trashed=with&filters=' . urlencode('{"$has":["siblingArticles"]}'),
            ['First Article', 'Second Article', 'Removed Article', 'Orphaned Article'],
        ];

        yield 'hasnt under with trashed' => [
            'trashed=with&filters=' . urlencode('{"$hasnt":["siblingArticles"]}'),
            [],
        ];

        yield 'relation document under only trashed' => [
            'trashed=only&filters=' . urlencode('{"siblingArticles":{}}'),
            ['Removed Article', 'Orphaned Article'],
        ];

        yield 'hasnt under only trashed' => [
            'trashed=only&filters=' . urlencode('{"$hasnt":["siblingArticles"]}'),
            [],
        ];

        yield 'closed relation stays live' => [
            'trashed=with&filters=' . urlencode('{"$hasnt":["authorComments"]}'),
            ['Orphaned Article'],
        ];
    }

    /**
     * Test that a relation filter and an existence check see the rows the
     * relation embeds, and that a closed relation stays live.
     *
     * Bob's only article and comment are trashed, so whether he matches turns
     * on the state each relation is filtered under.
     *
     * @param  string  $query
     * @param  array<int, string>  $titles
     * @return void
     */
    #[DataProvider('relationFilterProvider')]
    public function testRelationFiltersSeeTheRowsTheRelationEmbeds(string $query, array $titles): void
    {
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret', 'status' => 'active']);

        $this->seedArticle($bob->id, 'Orphaned Article', 'orphaned-article')->delete();

        Comment::create(['user_id' => $bob->id, 'body' => 'Removed'])->delete();

        $response = $this->getJson('/articles?fields[trashed_cascade_articles]=title&' . $query);

        $response->assertOk();
        $response->assertJsonPath('data.*.title', $titles);
    }

    /**
     * Test that a root that never soft deletes keeps its relations live-only by
     * default.
     *
     * @return void
     */
    public function testNonSoftDeletingRootKeepsRelationsLiveByDefault(): void
    {
        $response = $this->getJson('/authors');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.0.articles');
        $response->assertJsonCount(2, 'data.0.comments');
    }

    /**
     * Test that a root that never soft deletes refuses a trashed request
     * instead of widening only its children.
     *
     * @return void
     */
    public function testNonSoftDeletingRootRefusesATrashedRequest(): void
    {
        $response = $this->getJson('/authors?trashed=with');

        $response->assertUnprocessable();
        $response->assertJsonPath('error.meta.trashed', ['The trashed parameter is not permitted for this resource.']);
    }

    /**
     * Create a single article for the given author.
     *
     * @param  int  $authorId
     * @param  string  $title
     * @param  string  $slug
     * @return \Tests\Fixtures\Models\Article
     */
    private function seedArticle(int $authorId, string $title, string $slug): Article
    {
        return Article::create([
            'user_id' => $authorId,
            'title'   => $title,
            'slug'    => $slug,
            'body'    => str_repeat('lorem ipsum dolor ', 10),
            'summary' => 'A concise summary for the article fixture.',
            'status'  => 'published',
            'views'   => 10,
        ]);
    }
}
