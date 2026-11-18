<?php

declare(strict_types = 1);

namespace Tests\Feature\Caching;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\SoftDeleteVisibilityApplier;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\CacheableArticleRepository;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\HeaderGatedArticleResource;
use Tests\TestCase;

/**
 * Feature tests proving each trashed visibility of a cacheable repository read
 * is cached apart from the others.
 *
 * withTrashed() only removes a global scope, so a key taken before the scopes
 * apply would serve one visibility's rows, or its miss, to another.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SoftDeleteVisibilityApplier::class)]
#[CoversClass(ApiRepository::class)]
final class CacheableTrashedVisibilityTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up cacheable list and show routes over a live and a trashed article.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');

        $this->registerApiExceptionHandler();

        $author = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->seedArticle($author->id, 'Live', 'live');
        $this->seedArticle($author->id, 'Removed', 'removed')->delete();

        Route::middleware(ParseApiQuery::class)->get('/articles', function (CacheableArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->usingResource(ArticleResource::class)->withApiCriteria()->get(); // @phpstan-ignore staticMethod.dynamicCall

            return new ApiResourceCollection($articles, ArticleResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/articles/{slug}', function (string $slug, CacheableArticleRepository $repository): JsonResponse {

            $article = $repository->usingResource(ArticleResource::class)->withApiCriteria()->firstWhere('slug', $slug); // @phpstan-ignore staticMethod.dynamicCall

            return $article === null
                ? new JsonResponse(null, 404)
                : (new ArticleResource($article))->response();
        });

        Route::middleware(ParseApiQuery::class)->get('/gated-articles', function (CacheableArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->usingResource(HeaderGatedArticleResource::class)->withApiCriteria()->get(); // @phpstan-ignore staticMethod.dynamicCall

            return new ApiResourceCollection($articles, HeaderGatedArticleResource::class);
        });
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        DB::disableQueryLog();

        parent::tearDown();
    }

    /**
     * Test that a default read after a cached trashed=with read excludes the
     * trashed row.
     *
     * @return void
     */
    public function testDefaultReadAfterACachedWithReadExcludesTheTrashedRow(): void
    {
        self::assertSame(['Live', 'Removed'], $this->getJson('/articles?trashed=with')->assertOk()->json('data.*.title'));
        self::assertSame(['Live'], $this->getJson('/articles')->assertOk()->json('data.*.title'));
    }

    /**
     * Test that a trashed=with read after a cached default read includes the
     * trashed row.
     *
     * @return void
     */
    public function testWithReadAfterACachedDefaultReadIncludesTheTrashedRow(): void
    {
        self::assertSame(['Live'], $this->getJson('/articles')->assertOk()->json('data.*.title'));
        self::assertSame(['Live', 'Removed'], $this->getJson('/articles?trashed=with')->assertOk()->json('data.*.title'));
    }

    /**
     * Test that trashed=only is cached apart from both trashed=with and the
     * default.
     *
     * @return void
     */
    public function testOnlyReadIsCachedApartFromWithAndTheDefault(): void
    {
        $this->getJson('/articles')->assertOk();
        $this->getJson('/articles?trashed=with')->assertOk();

        self::assertSame(['Removed'], $this->getJson('/articles?trashed=only')->assertOk()->json('data.*.title'));
        self::assertSame(['Live', 'Removed'], $this->getJson('/articles?trashed=with')->assertOk()->json('data.*.title'));
        self::assertSame(['Live'], $this->getJson('/articles')->assertOk()->json('data.*.title'));
    }

    /**
     * Test that the show route finds a trashed row with trashed=with after the
     * default read of it cached a miss.
     *
     * @return void
     */
    public function testShowReadFindsTheTrashedRowAfterACachedMiss(): void
    {
        $this->getJson('/articles/removed')->assertNotFound();
        $this->getJson('/articles/removed?trashed=with')->assertOk()->assertJsonPath('data.title', 'Removed');
    }

    /**
     * Test that the show route misses a trashed row by default after a
     * trashed=with read of it cached the row.
     *
     * @return void
     */
    public function testShowReadMissesTheTrashedRowAfterACachedTrashedHit(): void
    {
        $this->getJson('/articles/removed?trashed=with')->assertOk()->assertJsonPath('data.title', 'Removed');
        $this->getJson('/articles/removed')->assertNotFound();
    }

    /**
     * Test that a request whose trashed gate is closed is refused after a
     * request whose gate is open cached the trashed rows for the same URL.
     *
     * @return void
     */
    public function testClosedGateIsRefusedAfterAnOpenGateCachedTheTrashedRows(): void
    {
        $open = $this->getJson('/gated-articles?trashed=with', [HeaderGatedArticleResource::HEADER => '1']);

        self::assertSame(['Live', 'Removed'], $open->assertOk()->json('data.*.title'));

        $this->getJson('/gated-articles?trashed=with')->assertUnprocessable();
    }

    /**
     * Test that a repeated trashed=with read is served from the cache with no
     * queries.
     *
     * @return void
     */
    public function testRepeatedWithReadIsServedWithZeroQueries(): void
    {
        $first = $this->getJson('/articles?trashed=with')->assertOk();

        DB::enableQueryLog();

        $repeat = $this->getJson('/articles?trashed=with')->assertOk();

        self::assertCount(0, DB::getQueryLog());
        self::assertSame($first->baseResponse->getContent(), $repeat->baseResponse->getContent());
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
            'body'    => 'Body',
            'summary' => 'Summary',
        ]);
    }
}
