<?php

declare(strict_types = 1);

namespace Tests\Integration\Resources;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Profile;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\ArticleRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Query-bound guard for rendering with narrowed columns and a warm metadata
 * cache, under the lazy-loading net.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(EagerLoadPlanner::class)]
final class NarrowedRenderQueryBoundTest extends TestCase
{
    /** @var string The user selection: nested relations, a relation accessor and aggregates. */
    private const string USER_QUERY = '/users?fields[users]=name,organization,profile_bio,posts,aggregates&fields[posts]=title,tags&fields[tags]=name';

    /** @var string The article selection: an extras-backed accessor and a needs-backed accessor. */
    private const string ARTICLE_QUERY = '/articles?fields[articles]=title,summary_excerpt,author_name';

    /** @var array<int, int> The tag ids attached to every seeded post. */
    private array $tagIds = [];

    /**
     * Set up the users and articles routes and a tag pool.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.resources.narrow_columns', true);

        Route::middleware(ParseApiQuery::class)->get('/users', static function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(UserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, UserResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/articles', static function (ArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->usingResource(ArticleResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($articles, ArticleResource::class);
        });

        $this->tagIds = [
            Tag::create(['name' => 'php'])->id,
            Tag::create(['name' => 'laravel'])->id,
        ];
    }

    /**
     * Test that nested relations, a relation accessor and aggregates render in
     * the same statements for 5 users as for 50.
     *
     * @return void
     */
    public function testUserGraphStatementCountIsConstantRegardlessOfRowCount(): void
    {
        $organization = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp']);

        $this->seedUsers(1, 5, $organization->id);
        $this->getJson(self::USER_QUERY)->assertOk();

        [$small] = $this->statementsFor(self::USER_QUERY);

        $this->seedUsers(6, 50, $organization->id);

        [$large, $response] = $this->statementsFor(self::USER_QUERY);

        // The count, users, organizations, profiles, posts and tags
        self::assertCount(6, $large);
        self::assertCount(count($small), $large);

        $response->assertJsonCount(50, 'data');
        $response->assertJsonPath('data.49.organization.name', 'Acme Corp');
        $response->assertJsonPath('data.49.profile_bio', 'Bio 50');
        $response->assertJsonPath('data.49.posts.*.title', ['Post 50a', 'Post 50b']);
        $response->assertJsonPath('data.49.posts.0.tags.*.name', ['php', 'laravel']);
        $response->assertJsonPath('data.49.aggregates.posts_count', 2);
    }

    /**
     * Test that an extras-backed accessor renders in the same statements for 5
     * articles as for 50.
     *
     * @return void
     */
    public function testExtrasAccessorStatementCountIsConstantRegardlessOfRowCount(): void
    {
        $this->seedArticles(1, 5);
        $this->getJson(self::ARTICLE_QUERY)->assertOk();

        [$small] = $this->statementsFor(self::ARTICLE_QUERY);

        $this->seedArticles(6, 50);

        [$large, $response] = $this->statementsFor(self::ARTICLE_QUERY);

        // The count, the narrowed articles and the extras-loaded authors
        self::assertCount(3, $large);
        self::assertCount(count($small), $large);
        self::assertStringNotContainsString('*', $large[1]);

        $response->assertJsonCount(50, 'data');
        $response->assertJsonPath('data.49.author_name', 'Author 50');
        $response->assertJsonPath('data.49.summary_excerpt', 'Summary 50');
    }

    /**
     * Request the given URI and return the statements it ran with the response.
     *
     * @param  string  $uri
     * @return array{0: array<int, string>, 1: \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>}
     */
    private function statementsFor(string $uri): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $response = $this->getJson($uri);
        } finally {
            DB::disableQueryLog();
        }

        $response->assertOk();

        return [array_column(DB::getQueryLog(), 'query'), $response];
    }

    /**
     * Seed the numbered users, each with a profile and two tagged posts.
     *
     * @param  int  $from
     * @param  int  $to
     * @param  int  $organizationId
     * @return void
     */
    private function seedUsers(int $from, int $to, int $organizationId): void
    {
        for ($number = $from; $number <= $to; $number++) {

            $user = User::create(['name' => 'User ' . $number, 'email' => 'user' . $number . '@example.com', 'organization_id' => $organizationId]);

            Profile::create(['user_id' => $user->id, 'bio' => 'Bio ' . $number]);

            foreach (['a', 'b'] as $suffix) {
                Post::create(['user_id' => $user->id, 'title' => 'Post ' . $number . $suffix, 'body' => 'Body'])->tags()->attach($this->tagIds);
            }
        }
    }

    /**
     * Seed the numbered articles, each by its own author.
     *
     * @param  int  $from
     * @param  int  $to
     * @return void
     */
    private function seedArticles(int $from, int $to): void
    {
        for ($number = $from; $number <= $to; $number++) {

            $author = User::create(['name' => 'Author ' . $number, 'email' => 'author' . $number . '@example.com']);

            Article::create([
                'user_id' => $author->id,
                'title'   => 'Article ' . $number,
                'slug'    => 'article-' . $number,
                'body'    => 'Body',
                'summary' => 'Summary ' . $number,
            ]);
        }
    }
}
