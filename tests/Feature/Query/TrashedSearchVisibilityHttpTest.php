<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SearchApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SoftDeleteVisibilityApplier;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Comment;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\ArticleRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\TrashedSearchArticleResource;
use Tests\Fixtures\Resources\TrashedSearchAuthorResource;
use Tests\Fixtures\Resources\TrashedSearchClosedArticleResource;
use Tests\Fixtures\Resources\TrashedSearchCommentResource;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Feature tests driving a search term and a trashed visibility request together
 * over the real HTTP pipeline.
 *
 * Each parameter is correct on its own, which is why the pair needs proving:
 * the visibility scope is resolved before the search, and the search
 * contributes a group of ORed predicates that has to stay a group. Isolating
 * the scope to trashed rows writes a plain predicate onto the builder rather
 * than a scope the query nests for itself, so a search group escaping its
 * wrapper would OR against that predicate and answer a trashed-only read with
 * live rows. A gate opening on anything other than the request would put
 * soft-deleted rows into a plain `?search=`. Neither is visible to a
 * search-only or a trashed-only request, each carrying half the pair.
 *
 * The rows are seeded so the term matches on both sides of the soft-delete
 * boundary and so rows exist that the term alone excludes, which is what makes
 * a widened scope observable as a widening rather than as an unnarrowed table.
 * The term appears in the same case in every seeded value, since one supported
 * engine matches patterns case-sensitively and another does not.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SearchApplier::class)]
#[CoversClass(SoftDeleteVisibilityApplier::class)]
#[CoversClass(RelationTrashedGate::class)]
#[CoversClass(ApiCriteria::class)]
final class TrashedSearchVisibilityHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up repository-backed routes over a searchable soft-deleting resource,
     * its gate-closed twin, and a searchable parent carrying a soft-deleting
     * relation, with a driver registered for the connection under test.
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

        $connection = DB::connection()->getDriverName();

        $this->app?->make(SearchDriverRegistry::class)->override($connection, new PatternSearchDriver);

        Config::set('api-toolkit.search.unverified_connections', [$connection]);
        Config::set('api-toolkit.resources.resource_map', [Comment::class => TrashedSearchCommentResource::class]);

        $this->seedArticles();
        $this->seedAuthors();

        Route::middleware(ParseApiQuery::class)->get('/searchable-articles', function (ArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->usingResource(TrashedSearchArticleResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($articles, TrashedSearchArticleResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/gate-closed-articles', function (ArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->usingResource(TrashedSearchClosedArticleResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($articles, TrashedSearchClosedArticleResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/searchable-authors', function (UserRepository $repository): ApiResourceCollection {

            $authors = $repository->usingResource(TrashedSearchAuthorResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($authors, TrashedSearchAuthorResource::class);
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
     * Test that a term carried on its own answers with live matches only, even
     * though a trashed row matches it and the resource would have opened its
     * gate had the request asked for it. The widening is the request's to ask
     * for, never the term's to imply.
     *
     * @return void
     */
    public function testSearchAloneNeverSurfacesATrashedMatch(): void
    {
        $response = $this->getJson('/searchable-articles?search=ledger');

        $response->assertOk();

        self::assertSame(['Public ledger update', 'Second ledger update'], $this->titles($response));
        $response->assertJsonPath('meta.total', 2);
    }

    /**
     * Test that a widened scope is still narrowed by the term, so the trashed
     * rows the request opens are the matching ones rather than all of them.
     *
     * @return void
     */
    public function testSearchStillNarrowsWhenTrashedRowsAreIncluded(): void
    {
        $response = $this->getJson('/searchable-articles?search=ledger&trashed=with');

        $response->assertOk();

        self::assertSame(
            ['Public ledger update', 'Second ledger update', 'Retracted ledger draft'],
            $this->titles($response),
        );
        $response->assertJsonPath('meta.total', 3);
    }

    /**
     * Test that a scope isolated to trashed rows is narrowed by the term as
     * well, so the non-matching trashed row stays out of the answer. This is
     * the read where the scope stands as a plain predicate beside the search
     * rather than as one the query nests for itself, so a search group that
     * escaped its wrapper would OR live rows back into the answer here.
     *
     * @return void
     */
    public function testSearchStillNarrowsWhenTheScopeIsIsolatedToTrashedRows(): void
    {
        $response = $this->getJson('/searchable-articles?search=ledger&trashed=only');

        $response->assertOk();

        self::assertSame(['Retracted ledger draft'], $this->titles($response));
        $response->assertJsonPath('meta.total', 1);
    }

    /**
     * Test that a resource holding its trashed gate closed keeps soft-deleted
     * rows hidden from a request that carries both parameters, so a search is
     * never the thing that opens soft-delete visibility.
     *
     * @return void
     */
    public function testTrashedWideningIsRefusedBesideASearchWhenTheGateIsClosed(): void
    {
        $response = $this->getJson('/gate-closed-articles?search=ledger&trashed=with');

        $response->assertOk();

        self::assertSame(['Public ledger update', 'Second ledger update'], $this->titles($response));
        $response->assertJsonPath('meta.total', 2);
    }

    /**
     * Test that a term, a widened scope, and a root-level filter disjunction
     * all narrow together on one request. The disjunction alone reaches three
     * rows and the term alone reaches three others; only their intersection may
     * answer, which no branch of either group can widen.
     *
     * @return void
     */
    public function testSearchTrashedAndARootLevelOrFilterAllNarrowTogether(): void
    {
        $filters = json_encode([
            '$or' => [
                'status' => ['$eq' => 'archived'],
                'views'  => ['$eq' => 99],
            ],
        ]);

        $query = http_build_query(['search' => 'ledger', 'trashed' => 'with', 'filters' => $filters]);

        $response = $this->getJson('/searchable-articles?' . $query);

        $response->assertOk();

        self::assertSame(['Second ledger update'], $this->titles($response));
        $response->assertJsonPath('meta.total', 1);
    }

    /**
     * Test that a term narrowing the parent leaves its eager-loaded children
     * live-only, the relation cascade staying closed on a request that asked
     * for no trashed rows.
     *
     * @return void
     */
    public function testSearchedParentKeepsRelationChildrenLiveByDefault(): void
    {
        $response = $this->getJson('/searchable-authors?search=archivist');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.name', 'Alice the archivist');
        $response->assertJsonCount(2, 'data.0.comments');
    }

    /**
     * Test that the same searched parent carries its trashed children when the
     * request asks for them, the term narrowing the root while the trashed
     * state is spent on the relation.
     *
     * @return void
     */
    public function testSearchedParentWidensRelationChildrenWhenTrashedRequested(): void
    {
        $response = $this->getJson('/searchable-authors?search=archivist&trashed=with');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.name', 'Alice the archivist');
        $response->assertJsonCount(3, 'data.0.comments');
    }

    /**
     * Test that a searched parent isolates its trashed children when the
     * request asks for those alone, the parent itself staying visible since it
     * never soft deletes.
     *
     * @return void
     */
    public function testSearchedParentIsolatesTrashedRelationChildren(): void
    {
        $response = $this->getJson('/searchable-authors?search=archivist&trashed=only');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.name', 'Alice the archivist');
        $response->assertJsonCount(1, 'data.0.comments');
        $response->assertJsonPath('data.0.comments.0.body', 'Retracted remark');
    }

    /**
     * Seed the article rows the root-level requests read.
     *
     * @return void
     */
    private function seedArticles(): void
    {
        $author = User::create(['name' => 'Iris Reporter', 'email' => 'iris@example.com', 'password' => 'secret', 'status' => 'active']);

        $this->seedArticle($author->id, 'Public ledger update', 'public-ledger-update', 'published', 10);
        $this->seedArticle($author->id, 'Second ledger update', 'second-ledger-update', 'published', 99);
        $this->seedArticle($author->id, 'Unrelated notice', 'unrelated-notice', 'archived', 10);
        $this->seedArticle($author->id, 'Retracted ledger draft', 'retracted-ledger-draft', 'draft', 10)->delete();
        $this->seedArticle($author->id, 'Retracted notice', 'retracted-notice', 'archived', 10)->delete();
    }

    /**
     * Seed the authors the relation-level requests read, one matching the term
     * and one not, each carrying live and trashed children.
     *
     * @return void
     */
    private function seedAuthors(): void
    {
        $matching = User::create(['name' => 'Alice the archivist', 'email' => 'alice@example.com', 'password' => 'secret', 'status' => 'active']);
        $other    = User::create(['name' => 'Bob the auditor', 'email' => 'bob@example.com', 'password' => 'secret', 'status' => 'active']);

        Comment::create(['user_id' => $matching->id, 'body' => 'Live remark one']);
        Comment::create(['user_id' => $matching->id, 'body' => 'Live remark two']);
        Comment::create(['user_id' => $matching->id, 'body' => 'Retracted remark'])->delete();

        Comment::create(['user_id' => $other->id, 'body' => 'Unmatched remark']);
    }

    /**
     * Create a single article for the given author.
     *
     * @param  int  $authorId
     * @param  string  $title
     * @param  string  $slug
     * @param  string  $status
     * @param  int  $views
     * @return \Tests\Fixtures\Models\Article
     */
    private function seedArticle(int $authorId, string $title, string $slug, string $status, int $views): Article
    {
        return Article::create([
            'user_id' => $authorId,
            'title'   => $title,
            'slug'    => $slug,
            'body'    => str_repeat('lorem ipsum dolor ', 10),
            'summary' => 'A concise summary for the article fixture.',
            'status'  => $status,
            'views'   => $views,
        ]);
    }

    /**
     * Extract the title column from a response data payload.
     *
     * @param  \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>  $response
     * @return array<int, string>
     */
    private function titles(TestResponse $response): array
    {
        return array_column((array) $response->json('data'), 'title');
    }
}
