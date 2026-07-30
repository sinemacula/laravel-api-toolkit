<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Comment;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\AuthorResource;
use Tests\Fixtures\Resources\CommentResource;
use Tests\TestCase;

/**
 * Feature tests proving trashed visibility cascades to eager-loaded relations
 * over the real HTTP pipeline.
 *
 * A single author is loaded with two soft-deleting child relations: articles,
 * whose resource opts in to trashed visibility, and comments, whose resource
 * does not. Driving the query parser plus repository pagination proves the
 * cascade is gated independently per relation: one `?trashed=with` request
 * widens the opted-in relation while the closed sibling stays live-only, the
 * safe-by-default guarantee proven end to end.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RelationTrashedGate::class)]
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(ApiCriteria::class)]
final class RelationTrashedVisibilityHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up an authors route with a soft-deleting article and comment child
     * relation, each carrying live and trashed rows.
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

        // The article gate opts in; the comment gate stays closed, so a single
        // request can prove per-relation gating in isolation.
        Config::set('api-toolkit.resources.resource_map', [
            Article::class => ArticleResource::class,
            Comment::class => CommentResource::class,
        ]);

        $author = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret', 'status' => 'active']);

        $this->seedArticle($author->id, 'First Article', 'first-article');
        $this->seedArticle($author->id, 'Second Article', 'second-article');
        $this->seedArticle($author->id, 'Removed Article', 'removed-article')->delete();

        Comment::create(['user_id' => $author->id, 'body' => 'Live one']);
        Comment::create(['user_id' => $author->id, 'body' => 'Live two']);
        Comment::create(['user_id' => $author->id, 'body' => 'Removed'])->delete();

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
     * Test that without a trashed parameter both relations return only live
     * children.
     *
     * @return void
     */
    public function testDefaultReturnsOnlyLiveChildren(): void
    {
        $response = $this->getJson('/authors');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.0.articles');
        $response->assertJsonCount(2, 'data.0.comments');
    }

    /**
     * Test that trashed=with includes trashed children for the relation whose
     * resource opts in.
     *
     * @return void
     */
    public function testWithIncludesTrashedChildrenWhenChildResourceOptsIn(): void
    {
        $response = $this->getJson('/authors?trashed=with');

        $response->assertOk();
        $response->assertJsonCount(3, 'data.0.articles');
    }

    /**
     * Test that the same trashed=with request that widens the opted-in article
     * relation still excludes trashed comments whose resource has not opted in.
     *
     * @return void
     */
    public function testWithExcludesTrashedChildrenWhenChildGateClosed(): void
    {
        $response = $this->getJson('/authors?trashed=with');

        $response->assertOk();
        $response->assertJsonCount(3, 'data.0.articles');
        $response->assertJsonCount(2, 'data.0.comments');
    }

    /**
     * Test that trashed=only isolates trashed children for the opted-in
     * relation while the closed sibling stays live-only.
     *
     * @return void
     */
    public function testOnlyReturnsOnlyTrashedChildrenForOptedInRelation(): void
    {
        $response = $this->getJson('/authors?trashed=only');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.0.articles');
        $response->assertJsonCount(2, 'data.0.comments');
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
