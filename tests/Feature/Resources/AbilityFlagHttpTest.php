<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Schema\Field;
use Tests\Concerns\RecordsStatements;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Policies\ArticlePolicy;
use Tests\Fixtures\Repositories\ArticleRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\RestorableArticleResource;
use Tests\Fixtures\Resources\RestorableAuthorResource;
use Tests\TestCase;

/**
 * Feature tests for per-row ability flags in a real JSON body.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Field::class)]
final class AbilityFlagHttpTest extends TestCase
{
    use RecordsStatements;

    /** @var string The article selection naming the flag. */
    private const string FLAGGED_ARTICLES = '/articles?fields[restorable_articles]=id,can_restore';

    /** @var \Tests\Fixtures\Models\User The author of the first article. */
    private User $alice;

    /** @var \Tests\Fixtures\Models\User The author of the second article. */
    private User $bob;

    /**
     * Set up the articles and authors routes over one article per author.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [RestorableArticleResource::class, RestorableAuthorResource::class]);

        Gate::policy(Article::class, ArticlePolicy::class);

        Route::middleware(ParseApiQuery::class)->get('/articles', static function (ArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->usingResource(RestorableArticleResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($articles, RestorableArticleResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/authors', static function (UserRepository $repository): ApiResourceCollection {

            $authors = $repository->usingResource(RestorableAuthorResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($authors, RestorableAuthorResource::class);
        });

        $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $this->bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);

        $this->seedArticle($this->alice);
        $this->seedArticle($this->bob);
    }

    /**
     * Test that each row answers for the signed-in user, who may restore only
     * the article they wrote.
     *
     * @return void
     */
    public function testEachRowAnswersForTheSignedInUser(): void
    {
        $this->actingAs($this->alice)->getJson(self::FLAGGED_ARTICLES)
            ->assertOk()
            ->assertJsonPath('data.*.can_restore', [true, false]);
    }

    /**
     * Test that a guest is denied on every row.
     *
     * @return void
     */
    public function testAGuestIsDeniedOnEveryRow(): void
    {
        $this->getJson(self::FLAGGED_ARTICLES)
            ->assertOk()
            ->assertJsonPath('data.*.can_restore', [false, false]);
    }

    /**
     * Test that the flag is left out unless the selection names it.
     *
     * @return void
     */
    public function testTheFlagIsLeftOutUnlessSelected(): void
    {
        $this->actingAs($this->alice)->getJson('/articles')
            ->assertOk()
            ->assertJsonPath('data.*.title', ['By Alice', 'By Bob'])
            ->assertJsonMissingPath('data.0.can_restore')
            ->assertJsonMissingPath('data.1.can_restore');
    }

    /**
     * Test that every embedded record answers for itself.
     *
     * @return void
     */
    public function testEachEmbeddedRecordAnswersForItself(): void
    {
        $this->actingAs($this->alice)->getJson('/authors?fields[restorable_authors]=articles&fields[restorable_articles]=can_restore')
            ->assertOk()
            ->assertJsonPath('data.0.articles.*.can_restore', [true])
            ->assertJsonPath('data.1.articles.*.can_restore', [false]);
    }

    /**
     * Test that a narrowed select still reads the column the policy needs.
     *
     * @return void
     */
    public function testANarrowedSelectKeepsTheColumnThePolicyReads(): void
    {
        Config::set('api-toolkit.resources.narrow_columns', true);

        $statements = $this->recordStatements(function (): void {
            $this->actingAs($this->alice)->getJson(self::FLAGGED_ARTICLES)
                ->assertOk()
                ->assertJsonPath('data.*.can_restore', [true, false]);
        });

        $statements = str_replace(['`', '"'], '', $statements);
        $select     = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'from articles') && !str_contains($sql, 'count(*)')));

        self::assertCount(1, $select);
        self::assertStringNotContainsString('*', $select[0]);
        self::assertStringContainsString('user_id', $select[0]);
    }

    /**
     * Test that consecutive requests by different users over one compiled
     * schema each get their own answers.
     *
     * @return void
     */
    public function testConsecutiveRequestsAnswerForTheirOwnUser(): void
    {
        $this->actingAs($this->alice)->getJson(self::FLAGGED_ARTICLES)->assertJsonPath('data.*.can_restore', [true, false]);
        $this->actingAs($this->bob)->getJson(self::FLAGGED_ARTICLES)->assertJsonPath('data.*.can_restore', [false, true]);
    }

    /**
     * Seed one article written by the given author.
     *
     * @param  \Tests\Fixtures\Models\User  $author
     * @return void
     */
    private function seedArticle(User $author): void
    {
        Article::create([
            'user_id' => $author->id,
            'title'   => 'By ' . $author->name,
            'slug'    => 'by-' . $author->id,
            'body'    => 'Body',
            'summary' => 'Summary',
        ]);
    }
}
