<?php

declare(strict_types = 1);

namespace Tests\Integration\Search;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\ArticleRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\EngineSearchUserResource;
use Tests\Fixtures\Resources\EqualityPrefixSearchableUserResource;
use Tests\Fixtures\Resources\TrashedSearchArticleResource;
use Tests\TestCase;

/**
 * Shared HTTP search suite run through each engine's own search driver.
 *
 * Rows are committed, not rolled back: one engine updates its full-text index
 * only when a transaction commits.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class EngineSearchHttpTestCase extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var string The term every relevance test searches for */
    private const string TERM = 'smith';

    /** @var array<int, string> The matches for the term, best first, ties in key order */
    private const array RANKED = ['Smith', 'SMITH', 'Smithers', 'Ann Smith', 'Ann Blacksmith-Jones'];

    /**
     * Register the search routes and seed the rows, skipping other engines.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== $this->engine()) {
            static::markTestSkipped(sprintf('The %s search suite runs against its own engine only.', $this->engine()));
        }

        $this->registerApiExceptionHandler();
        $this->serveSearches();
        $this->registerRoutes();

        User::create(['name' => 'Ann Blacksmith-Jones', 'email' => 'ann.jones@example.com', 'status' => 'active']);
        User::create(['name' => 'Smithers', 'email' => 'waylon@example.com', 'status' => 'inactive']);
        User::create(['name' => 'Ann Smith', 'email' => 'ann.s@example.com', 'status' => 'active']);
        User::create(['name' => 'Smith', 'email' => 'js@example.com', 'status' => 'active']);
        User::create(['name' => 'SMITH', 'email' => 'caps@example.com', 'status' => 'inactive']);
        User::create(['name' => 'Jones', 'email' => 'jones@example.com', 'status' => 'inactive']);
    }

    /**
     * Remove the committed rows before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === $this->engine()) {
            DB::table('posts')->delete();
            DB::table('articles')->delete();
            DB::table('users')->delete();
        }

        parent::tearDown();
    }

    /**
     * Leave the rows a test writes committed.
     *
     * @return void
     */
    #[\Override]
    public function beginDatabaseTransaction(): void {}

    /**
     * Test that a search, a root-level filter disjunction, an order and an
     * embedded relation compose on one page, with the total counted.
     *
     * @return void
     */
    public function testSearchFilterOrderAndEmbeddedRelationComposeOnOnePage(): void
    {
        $author = User::query()->where('name', 'Ann Smith')->firstOrFail();

        Post::create(['user_id' => $author->id, 'title' => 'Field notes', 'body' => 'notes']);

        $query = ['search' => self::TERM, 'filters' => $this->annSmithOrInactive(), 'order' => 'name', 'limit' => 2];

        $response = $this->getJson('/users?' . http_build_query([...$query, 'fields' => ['engine_search_users' => 'name,posts']]));

        $response->assertOk();
        $response->assertJsonPath('meta.total', 3);

        static::assertSame(['Ann Smith', 'SMITH'], $this->names($response));
        static::assertSame(['Field notes'], array_column((array) $response->json('data.0.posts'), 'title'));
        static::assertSame([], $response->json('data.1.posts'));

        $next = $this->getJson('/users?' . http_build_query([...$query, 'page' => 2]));

        static::assertSame(['Smithers'], $this->names($next));
        $next->assertJsonPath('meta.total', 3);
    }

    /**
     * Test that following the next cursor visits every match exactly once.
     *
     * @return void
     */
    public function testCursorPagesVisitEveryMatchOnce(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'search'     => self::TERM,
            'filters'    => $this->annSmithOrInactive(),
            'order'      => 'name',
            'limit'      => 2,
            'pagination' => 'cursor',
        ]));

        $names = $this->names($response);

        while (is_string($next = $response->json('links.next'))) {

            $cursor = parse_url($next, PHP_URL_QUERY);

            static::assertIsString($cursor);

            $response = $this->getJson('/users?' . $cursor);

            $response->assertOk();

            $names = [...$names, ...$this->names($response)];
        }

        static::assertSame(['Ann Smith', 'SMITH', 'Smithers'], $names);
    }

    /**
     * Test that a search beside a widened trashed scope and a filter
     * disjunction counts only the rows all three admit.
     *
     * @return void
     */
    public function testSearchNarrowsATrashedScopeBesideAFilterDisjunction(): void
    {
        $author = User::query()->firstOrFail();

        $this->seedArticle($author->id, 'Public ledger update', 'published', 10);
        $this->seedArticle($author->id, 'Second ledger update', 'published', 99);
        $this->seedArticle($author->id, 'Unrelated notice', 'archived', 99);
        $this->seedArticle($author->id, 'Retracted ledger archive', 'archived', 10)->delete();

        $filters = json_encode(['$or' => [['status' => ['$eq' => 'archived']], ['views' => ['$eq' => 99]]]]);

        $response = $this->getJson('/articles?' . http_build_query(['search' => 'ledger', 'trashed' => 'with', 'filters' => $filters]));

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);

        static::assertEqualsCanonicalizing(
            ['Second ledger update', 'Retracted ledger archive'],
            array_column((array) $response->json('data'), 'title'),
        );
    }

    /**
     * Test that relevance orders the whole value, then the start, then the
     * start of a word, then anywhere, folding case as matching does.
     *
     * @return void
     */
    public function testRelevanceRanksTheWholeValueThenTheStartThenAWordThenAnywhere(): void
    {
        $response = $this->relevance();

        $response->assertOk();
        $response->assertJsonPath('meta.total', 5);

        static::assertSame(self::RANKED, $this->names($response));
    }

    /**
     * Test that a later relevance page continues the ranking with the total
     * still counted over every match.
     *
     * @return void
     */
    public function testRelevancePagesByOffsetWithTheTotalUnchanged(): void
    {
        $response = $this->relevance(['limit' => 2, 'page' => 2]);

        $response->assertOk();
        $response->assertJsonPath('meta.total', 5);

        static::assertSame(['Smithers', 'Ann Smith'], $this->names($response));
    }

    /**
     * Test that a row ranks by its better searchable column.
     *
     * @return void
     */
    public function testARowRanksByItsBetterColumn(): void
    {
        User::create(['name' => 'Lee Brown', 'email' => 'smith.lee@example.com', 'status' => 'active']);

        static::assertSame(
            ['Smith', 'SMITH', 'Smithers', 'Lee Brown', 'Ann Smith', 'Ann Blacksmith-Jones'],
            $this->names($this->relevance()),
        );
    }

    /**
     * Test that a key after relevance breaks a tie within a tier.
     *
     * @return void
     */
    public function testAKeyAfterRelevanceBreaksATieWithinATier(): void
    {
        User::create(['name' => 'Zoe Smith', 'email' => 'zoe@example.com', 'status' => 'active']);

        $names = $this->names($this->relevance(['order' => 'relevance,name:desc']));

        // Collations disagree on how to order a pair differing only in case
        static::assertEqualsCanonicalizing(['Smith', 'SMITH'], array_slice($names, 0, 2));
        static::assertSame(['Smithers', 'Zoe Smith', 'Ann Smith', 'Ann Blacksmith-Jones'], array_slice($names, 2));
    }

    /**
     * Test that an order a repository scope adds sorts after relevance.
     *
     * @return void
     */
    public function testARepositoryScopeOrderSortsAfterRelevance(): void
    {
        $response = $this->getJson('/users-by-email?' . http_build_query(['search' => self::TERM, 'order' => 'relevance']));

        static::assertSame(['SMITH', 'Smith', 'Smithers', 'Ann Smith', 'Ann Blacksmith-Jones'], $this->names($response));
    }

    /**
     * Test that a whole-value match on an equality column ranks ahead of a
     * prefix match on another column.
     *
     * @return void
     */
    public function testAnEqualityMatchRanksAheadOfAPrefixMatch(): void
    {
        User::create(['name' => 'Zed Okafor', 'email' => 'okafor.z@example.com', 'status' => 'active']);
        User::create(['name' => 'Okafor', 'email' => 'kemi@example.com', 'status' => 'active']);

        $response = $this->getJson('/equality-prefix-users?' . http_build_query(['search' => 'Okafor', 'order' => 'relevance']));

        $response->assertOk();

        static::assertSame(['Okafor', 'Zed Okafor'], $this->names($response));
    }

    /**
     * Test that relevance without a search term is refused.
     *
     * @return void
     */
    public function testRelevanceWithoutASearchTermIsRejected(): void
    {
        $response = $this->getJson('/users?order=relevance');

        $response->assertStatus(422);

        static::assertSame(
            ['The relevance order ranks search matches, so it needs a search term.'],
            ((array) $response->json('error.meta'))['order.relevance'] ?? null,
        );
    }

    /**
     * Test that relevance is refused on a cursor-paginated read.
     *
     * @return void
     */
    public function testRelevanceIsRejectedUnderCursorPagination(): void
    {
        $response = $this->relevance(['pagination' => 'cursor']);

        $response->assertStatus(422);

        static::assertSame(
            ['Relevance order cannot be paged by cursor; use offset pagination.'],
            ((array) $response->json('error.meta'))['order.relevance'] ?? null,
        );
    }

    /**
     * Return the connection driver name this suite runs against.
     *
     * @return string
     */
    abstract protected function engine(): string;

    /**
     * Let the connection serve every surface these tests search.
     *
     * @return void
     */
    abstract protected function serveSearches(): void;

    /**
     * Request the relevance order for the term over the searchable route.
     *
     * @param  array<string, int|string>  $parameters
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    protected function relevance(array $parameters = []): TestResponse
    {
        return $this->getJson('/users?' . http_build_query($parameters + ['search' => self::TERM, 'order' => 'relevance']));
    }

    /**
     * Extract the name column from a response data payload.
     *
     * @param  \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>  $response
     * @return array<int, string>
     */
    protected function names(TestResponse $response): array
    {
        return array_column((array) $response->json('data'), 'name');
    }

    /**
     * Register the searchable user, scoped user, mixed-strategy user and
     * article routes.
     *
     * @return void
     */
    private function registerRoutes(): void
    {
        Route::middleware(ParseApiQuery::class)->get('/users', fn (UserRepository $repository): ApiResourceCollection => new ApiResourceCollection(
            $repository->usingResource(EngineSearchUserResource::class)->withApiCriteria()->paginate(),
            EngineSearchUserResource::class,
        ));

        Route::middleware(ParseApiQuery::class)->get('/users-by-email', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(EngineSearchUserResource::class)
                ->withApiCriteria()
                ->addScope(static function (Builder $query): void {
                    $query->getQuery()->orderBy('email');
                })
                ->paginate();

            return new ApiResourceCollection($users, EngineSearchUserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/equality-prefix-users', fn (UserRepository $repository): ApiResourceCollection => new ApiResourceCollection(
            $repository->usingResource(EqualityPrefixSearchableUserResource::class)->withApiCriteria()->paginate(),
            EqualityPrefixSearchableUserResource::class,
        ));

        Route::middleware(ParseApiQuery::class)->get('/articles', fn (ArticleRepository $repository): ApiResourceCollection => new ApiResourceCollection(
            $repository->usingResource(TrashedSearchArticleResource::class)->withApiCriteria()->paginate(),
            TrashedSearchArticleResource::class,
        ));
    }

    /**
     * Encode a filter admitting Ann Smith or any inactive user.
     *
     * @return string
     */
    private function annSmithOrInactive(): string
    {
        return (string) json_encode(['$or' => [['name' => ['$eq' => 'Ann Smith']], ['status' => ['$eq' => 'inactive']]]]);
    }

    /**
     * Create one article for the given author.
     *
     * @param  int  $authorId
     * @param  string  $title
     * @param  string  $status
     * @param  int  $views
     * @return \Tests\Fixtures\Models\Article
     */
    private function seedArticle(int $authorId, string $title, string $status, int $views): Article
    {
        return Article::create([
            'user_id' => $authorId,
            'title'   => $title,
            'slug'    => str_replace(' ', '-', strtolower($title)),
            'body'    => 'body',
            'summary' => 'summary',
            'status'  => $status,
            'views'   => $views,
        ]);
    }
}
