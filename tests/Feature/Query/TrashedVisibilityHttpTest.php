<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * Feature tests for trashed visibility over the real HTTP pipeline, on
 * collection and single-record reads.
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

    /** @var string The refusal for a root that cannot honour the parameter */
    private const string NOT_PERMITTED = 'The trashed parameter is not permitted for this resource.';

    /** @var string The refusal for a value outside with and only */
    private const string INVALID = 'The selected trashed is invalid.';

    /** @var string The refusal for a present but empty value */
    private const string EMPTY = 'The trashed field must have a value.';

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

        Route::middleware(ParseApiQuery::class)->post('/articles', static fn (): JsonResponse => new JsonResponse(null, 201));

        Route::middleware(ParseApiQuery::class)->get('/articles/{slug}', function (string $slug, ArticleRepository $repository): JsonResponse {

            $article = $repository->withApiCriteria()->firstWhere('slug', $slug); // @phpstan-ignore staticMethod.dynamicCall

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
     * Test that trashed=with is refused when the resource keeps the gate
     * closed.
     *
     * @return void
     */
    public function testWithIsRefusedWhenResourceGateClosed(): void
    {
        $this->gateThrough(RestrictedArticleResource::class);

        $this->assertTrashedRefused($this->getJson('/articles?trashed=with'), self::NOT_PERMITTED);
    }

    /**
     * Provide trashed values the parameter does not accept.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function invalidValueProvider(): iterable
    {
        yield 'misspelt' => ['trashed=onyl', self::INVALID];
        yield 'unknown' => ['trashed=deleted', self::INVALID];
        yield 'upper case' => ['trashed=ONLY', self::INVALID];
        yield 'list' => ['trashed[]=only', self::INVALID];
        yield 'empty' => ['trashed=', self::EMPTY];
    }

    /**
     * Test that a trashed value other than with or only is refused.
     *
     * @param  string  $query
     * @param  string  $message
     * @return void
     */
    #[DataProvider('invalidValueProvider')]
    public function testInvalidTrashedValueIsRefused(string $query, string $message): void
    {
        $this->gateThrough(ArticleResource::class);

        $this->assertTrashedRefused($this->getJson('/articles?' . $query), $message);
    }

    /**
     * Test that a write body carrying a trashed attribute is accepted, as the
     * query parser reads the query string only.
     *
     * @return void
     */
    public function testWriteBodyCarryingATrashedAttributeIsIgnored(): void
    {
        $this->postJson('/articles', ['trashed' => 'deleted'])->assertCreated();
    }

    /**
     * Test that a single-record read widens the same way a collection read
     * does.
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
     * Test that a single-record read is refused while the gate is closed.
     *
     * @return void
     */
    public function testSingleRecordReadIsRefusedWhenResourceGateClosed(): void
    {
        $this->gateThrough(RestrictedArticleResource::class);

        $this->assertTrashedRefused($this->getJson('/articles/removed-article?trashed=with'), self::NOT_PERMITTED);
    }

    /**
     * Assert the response is a 422 naming the trashed parameter.
     *
     * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @param  string  $message
     * @return void
     */
    private function assertTrashedRefused(TestResponse $response, string $message): void
    {
        $response->assertUnprocessable();
        $response->assertJsonPath('error.meta.trashed', [$message]);
    }

    /**
     * Point the Article model at the given resource for the criteria to resolve
     * its trashed gate.
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
