<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationColumnNarrower;
use SineMacula\ApiToolkit\Schema\FieldColumnMapper;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\ArticleRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\AuthorResource;
use Tests\Fixtures\Resources\BoundedOrganizationResource;
use Tests\Fixtures\Resources\BoundedPostResource;
use Tests\Fixtures\Resources\BoundedUserResource;
use Tests\Fixtures\Resources\CommentResource;
use Tests\Fixtures\Resources\CountryResource;
use Tests\Fixtures\Resources\NoteResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\TrashedCascadeArticleResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests for narrowing embedded relations to the columns they render.
 *
 * Each response is compared with the same request served unnarrowed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RelationColumnNarrower::class)]
#[CoversClass(ApiCriteria::class)]
final class EmbeddedColumnNarrowingHttpTest extends TestCase
{
    use RecordsStatements, RegistersApiExceptionHandler;

    /**
     * Register the routes and seed users with posts, tags, notes and
     * soft-deleting articles.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [
            ArticleResource::class,
            AuthorResource::class,
            BoundedOrganizationResource::class,
            BoundedPostResource::class,
            BoundedUserResource::class,
            CommentResource::class,
            CountryResource::class,
            NoteResource::class,
            OrganizationResource::class,
            PostResource::class,
            TagResource::class,
            TrashedCascadeArticleResource::class,
            UserResource::class,
        ]);

        Config::set('api-toolkit.resources.resource_map', [Article::class => ArticleResource::class]);

        FieldColumnMapper::clearCache();
        SchemaCompiler::clearCache();

        $this->registerApiExceptionHandler();
        $this->registerRoutes();
        $this->seedGraph();
    }

    /**
     * Clear the schema caches.
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
     * Test that a relation field narrows its parent, and the embedded posts
     * select only their rendered columns and keys.
     *
     * @return void
     */
    public function testParentAndEmbeddedRelationSelectOnlyWhatTheyRender(): void
    {
        $statements = $this->narrowedLikeUnnarrowed('/users?fields[users]=name,posts&fields[posts]=title');

        $this->assertExecuted($statements, 'select users.name, users.id from users');
        $this->assertExecuted($statements, 'select posts.title, posts.id, posts.user_id, row_number()');
    }

    /**
     * Test that a timestamp field narrows an embedded relation to its column.
     *
     * @return void
     */
    public function testAnEmbeddedTimestampFieldNarrowsToItsColumn(): void
    {
        $statements = $this->narrowedLikeUnnarrowed('/users?fields[users]=name,posts&fields[posts]=title,created_at');

        $this->assertExecuted($statements, 'select posts.title, posts.created_at, posts.id, posts.user_id, row_number()');
    }

    /**
     * Test that every recognised relation type, nested paths, a declared limit
     * and a child aggregate render as they do unnarrowed.
     *
     * @return void
     */
    public function testEveryRelationTypeRendersAsItDoesUnnarrowed(): void
    {
        $users = $this->narrowedLikeUnnarrowed('/users?' . http_build_query([
            'fields'     => ['users' => 'name,organization,posts', 'organizations' => 'name', 'posts' => 'title,user,tags,aggregates', 'tags' => 'name'],
            'aggregates' => ['posts' => 'tags_count'],
        ]));
        $notes = $this->narrowedLikeUnnarrowed('/bounded-users?fields[bounded_users]=name,notes&fields[notes]=title,subject');
        $orgs  = $this->narrowedLikeUnnarrowed('/organizations?fields[bounded_organizations]=name,posts&fields[bounded_posts]=title');

        $this->assertExecuted($users, 'select organizations.name, organizations.id from organizations');
        $this->assertExecuted($users, 'select posts.title, posts.id, posts.user_id, (select count(*) from tags');
        $this->assertExecuted($users, 'select tags.name, tags.id, post_tag.post_id as pivot_post_id');
        $this->assertExecuted($notes, 'select notes.title, notes.id, notes.noteable_id, notes.noteable_type, row_number()');
        $this->assertExecuted($orgs, 'select posts.title, posts.id, users.organization_id as laravel_through_key');
    }

    /**
     * Test that a soft-deleting child keeps its deleted-at column, so the
     * trashed scope still applies to it.
     *
     * @return void
     */
    public function testATrashedScopedChildKeepsItsSoftDeleteColumn(): void
    {
        $uri        = '/articles?trashed=with&fields[trashed_cascade_articles]=title,siblingArticles&fields[articles]=title';
        $statements = $this->narrowedLikeUnnarrowed($uri);

        $this->assertExecuted($statements, 'select articles.title, articles.id, articles.deleted_at, articles.user_id, row_number()');
        $this->getJson($uri)->assertJsonCount(2, 'data.0.siblingArticles');
    }

    /**
     * Test that an embedded scalar over a model accessor leaves its path full
     * and still renders the accessor.
     *
     * @return void
     */
    public function testAnEmbeddedScalarOverAnAccessorLeavesItsPathFull(): void
    {
        $statements = $this->narrowedLikeUnnarrowed('/authors?fields[authors]=name,articles&fields[articles]=title,headline');

        $this->assertExecuted($statements, 'select *, row_number() over (partition by articles.user_id');
        $this->getJson('/authors?fields[authors]=articles&fields[articles]=headline')->assertJsonPath('data.0.articles.0.headline', 'FIRST ARTICLE');
    }

    /**
     * Serve the request unnarrowed then narrowed, assert both responses are
     * identical and return the narrowed request's statements, unquoted.
     *
     * @param  string  $uri
     * @return list<string>
     */
    private function narrowedLikeUnnarrowed(string $uri): array
    {
        Config::set('api-toolkit.resources.narrow_columns', false);

        $unnarrowed = $this->getJson($uri)->assertOk()->baseResponse->getContent();

        Config::set('api-toolkit.resources.narrow_columns', true);

        $narrowed   = null;
        $statements = $this->recordStatements(function () use ($uri, &$narrowed): void {
            $narrowed = $this->getJson($uri)->assertOk()->baseResponse->getContent();
        });

        self::assertSame($unnarrowed, $narrowed);

        return array_map(static fn (string $sql): string => str_replace(['`', '"'], '', $sql), $statements);
    }

    /**
     * Assert one of the statements contains the given SQL.
     *
     * @param  list<string>  $statements
     * @param  string  $sql
     * @return void
     */
    private function assertExecuted(array $statements, string $sql): void
    {
        $matching = array_filter($statements, static fn (string $statement): bool => str_contains($statement, $sql));

        self::assertNotSame([], $matching, sprintf("No statement contains \"%s\":\n%s", $sql, implode("\n", $statements)));
    }

    /**
     * Register the user, author, bounded user, article and organization routes.
     *
     * @return void
     */
    private function registerRoutes(): void
    {
        foreach (['/users' => UserResource::class, '/authors' => AuthorResource::class, '/bounded-users' => BoundedUserResource::class] as $uri => $resource) {
            Route::middleware(ParseApiQuery::class)->get($uri, static function (UserRepository $repository) use ($resource): ApiResourceCollection {

                $users = $repository->usingResource($resource)->withApiCriteria()->paginate();

                return new ApiResourceCollection($users, $resource);
            });
        }

        Route::middleware(ParseApiQuery::class)->get('/articles', static function (ArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->usingResource(TrashedCascadeArticleResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($articles, TrashedCascadeArticleResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/organizations', static function (ApiCriteria $criteria): ApiResourceCollection {

            $organizations = $criteria->usingResource(BoundedOrganizationResource::class)->apply(new Organization)->orderBy('organizations.id')->get();

            return new ApiResourceCollection($organizations, BoundedOrganizationResource::class);
        });
    }

    /**
     * Seed an organization of two users, their posts, tags and notes, and one
     * live and one removed article.
     *
     * @return void
     */
    private function seedGraph(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'slug' => 'acme']);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active', 'organization_id' => $organization->id]);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active', 'organization_id' => $organization->id]);

        $first = Post::create(['user_id' => $alice->id, 'title' => 'First', 'body' => str_repeat('body ', 50), 'published' => true]);

        Post::create(['user_id' => $alice->id, 'title' => 'Second', 'body' => 'Second body']);
        Post::create(['user_id' => $bob->id, 'title' => 'Third', 'body' => 'Third body']);

        $first->tags()->attach([Tag::create(['name' => 'php'])->id => ['role' => 'primary'], Tag::create(['name' => 'laravel'])->id => ['role' => 'secondary']]);

        $alice->notes()->forceCreate(['title' => 'Alice note']);
        $first->notes()->forceCreate(['title' => 'Post note']);

        $article = ['user_id' => $alice->id, 'body' => 'Body', 'summary' => 'Summary', 'status' => 'published', 'views' => 1];

        Article::create(['title' => 'First Article', 'slug' => 'first-article', ...$article]);
        Article::create(['title' => 'Removed Article', 'slug' => 'removed-article', ...$article])->delete();
    }
}
