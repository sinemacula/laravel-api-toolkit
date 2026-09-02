<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SoftDeleteVisibilityApplier;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\ArticleRepository;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\RestrictedArticleResource;
use Tests\TestCase;

/**
 * Feature tests proving trashed visibility over the real HTTP pipeline.
 *
 * Drives the query-parser middleware plus repository reads so the `?trashed`
 * parameter is observed end to end: a resource that opts in widens the
 * soft-delete scope to include or isolate trashed rows, while a resource that
 * has not opted in keeps them hidden regardless of the request - the
 * safe-by-default guarantee proven through a real request. Both read actions
 * the document advertises the parameter on are driven, the single-record read
 * included, since a widening the document offers on a show has to be one a
 * repository-backed show honours.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SoftDeleteVisibilityApplier::class)]
#[CoversClass(ApiCriteria::class)]
final class TrashedVisibilityHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up a repository-backed articles route with live and trashed rows.
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

        $author = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret', 'status' => 'active']);

        $this->seedArticle($author->id, 'First Article', 'first-article', 'published');
        $this->seedArticle($author->id, 'Second Article', 'second-article', 'published');
        $this->seedArticle($author->id, 'Removed Article', 'removed-article', 'draft')->delete();

        Route::middleware(ParseApiQuery::class)->get('/articles', function (ArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->withApiCriteria()->paginate();

            return new ApiResourceCollection($articles, ArticleResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/articles/{slug}', function (string $slug, ArticleRepository $repository): JsonResponse {

            $article = $repository->withApiCriteria()->firstWhere('slug', $slug);

            return $article === null
                ? new JsonResponse(null, 404)
                : (new ArticleResource($article))->response();
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
     * Test that a request without the trashed parameter returns only live rows.
     *
     * @return void
     */
    public function testDefaultReturnsOnlyLiveRows(): void
    {
        $this->gateThrough(ArticleResource::class);

        $response = $this->getJson('/articles');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    /**
     * Test that trashed=with includes trashed rows when the resource opts in.
     *
     * @return void
     */
    public function testWithIncludesTrashedWhenResourceOptsIn(): void
    {
        $this->gateThrough(ArticleResource::class);

        $response = $this->getJson('/articles?trashed=with');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    /**
     * Test that trashed=only isolates trashed rows when the resource opts in.
     *
     * @return void
     */
    public function testOnlyReturnsTrashedWhenResourceOptsIn(): void
    {
        $this->gateThrough(ArticleResource::class);

        $response = $this->getJson('/articles?trashed=only');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    /**
     * Test that trashed=with is ignored when the resource keeps the gate
     * closed, so soft-deleted rows are never exposed by default.
     *
     * @return void
     */
    public function testWithIsIgnoredWhenResourceGateClosed(): void
    {
        $this->gateThrough(RestrictedArticleResource::class);

        $response = $this->getJson('/articles?trashed=with');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    /**
     * Test that a single-record read widens the same way a collection read
     * does, so the visibility the document offers on a show is one a
     * repository-backed show honours rather than a claim about a collection.
     *
     * @return void
     */
    public function testSingleRecordReadWidensWhenResourceOptsIn(): void
    {
        $this->gateThrough(ArticleResource::class);

        $this->getJson('/articles/removed-article')->assertNotFound();

        $response = $this->getJson('/articles/removed-article?trashed=with');

        $response->assertOk();
        $response->assertJsonPath('data.title', 'Removed Article');
    }

    /**
     * Test that a single-record read keeps a trashed row hidden while the
     * resource keeps the gate closed, the widening being an opt-in on both read
     * actions alike.
     *
     * @return void
     */
    public function testSingleRecordReadStaysNarrowWhenResourceGateClosed(): void
    {
        $this->gateThrough(RestrictedArticleResource::class);

        $this->getJson('/articles/removed-article?trashed=with')->assertNotFound();
    }

    /**
     * Point the Article model at the given resource so the criteria resolves
     * its trashed gate through it.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Http\Resources\ApiResource>  $resource
     * @return void
     */
    private function gateThrough(string $resource): void
    {
        Config::set('api-toolkit.resources.resource_map', [Article::class => $resource]);
    }

    /**
     * Create a single article for the given author.
     *
     * @param  int  $authorId
     * @param  string  $title
     * @param  string  $slug
     * @param  string  $status
     * @return \Tests\Fixtures\Models\Article
     */
    private function seedArticle(int $authorId, string $title, string $slug, string $status): Article
    {
        return Article::create([
            'user_id' => $authorId,
            'title'   => $title,
            'slug'    => $slug,
            'body'    => str_repeat('lorem ipsum dolor ', 10),
            'summary' => 'A concise summary for the article fixture.',
            'status'  => $status,
            'views'   => 10,
        ]);
    }
}
