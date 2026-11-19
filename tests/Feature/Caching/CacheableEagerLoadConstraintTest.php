<?php

declare(strict_types = 1);

namespace Tests\Feature\Caching;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\TenantUser;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\CacheableArticleRepository;
use Tests\Fixtures\Repositories\CacheableOrganizationRepository;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\HeaderGatedArticleResource;
use Tests\Fixtures\Resources\HeaderGatedSiblingsArticleResource;
use Tests\Fixtures\Resources\TrashedCascadeArticleResource;
use Tests\Fixtures\Resources\ViewerOrganizationResource;
use Tests\Fixtures\Support\CurrentTenant;
use Tests\TestCase;

/**
 * Feature tests proving a cacheable read's eager loads are keyed by the query
 * each one runs, not by the constraint declared for it.
 *
 * A constraint that reads the authenticated user, a related model's tenant
 * scope and a relation widened to trashed rows all change the relation query
 * while the declaration stays the same.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EagerLoadApplier::class)]
#[CoversClass(RelationTrashedGate::class)]
final class CacheableEagerLoadConstraintTest extends TestCase
{
    /** @var string The organizations URL embedding the viewer's posts */
    private const string POSTS_URL = '/organizations?fields[viewer_organizations]=name,posts';

    /** @var string The organizations URL embedding the tenant's members */
    private const string MEMBERS_URL = '/organizations?fields[viewer_organizations]=name,members';

    /**
     * Set up cacheable organization and article routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Config::set('api-toolkit.resources.resource_map', [Article::class => ArticleResource::class]);
        Config::set(ResourceDiscovery::RESOURCES_KEY, [ViewerOrganizationResource::class]);

        Route::middleware(ParseApiQuery::class)->get('/organizations', function (CacheableOrganizationRepository $repository): ApiResourceCollection {

            $organizations = $repository->usingResource(ViewerOrganizationResource::class)->withApiCriteria()->get(); // @phpstan-ignore staticMethod.dynamicCall

            return new ApiResourceCollection($organizations, ViewerOrganizationResource::class);
        });

        Route::middleware(ParseApiQuery::class)->get('/articles', function (CacheableArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->usingResource(TrashedCascadeArticleResource::class)->withApiCriteria()->get(); // @phpstan-ignore staticMethod.dynamicCall

            return new ApiResourceCollection($articles, TrashedCascadeArticleResource::class);
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
     * Test that two users reading the same URL each get the related rows their
     * constraint selects, and that a repeat is served from the cache.
     *
     * @return void
     */
    public function testConstraintReadingTheAuthenticatedUserIsCachedPerUser(): void
    {
        $organization = Organization::create(['name' => 'Acme', 'slug' => 'acme']);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'organization_id' => $organization->id]);
        $bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'organization_id' => $organization->id]);

        Post::create(['user_id' => $alice->id, 'title' => 'By Alice', 'body' => 'Body']);
        Post::create(['user_id' => $bob->id, 'title' => 'By Bob', 'body' => 'Body']);

        self::assertSame(['By Alice'], $this->actingAs($alice)->getJson(self::POSTS_URL)->assertOk()->json('data.0.posts.*.title'));
        self::assertSame(['By Bob'], $this->actingAs($bob)->getJson(self::POSTS_URL)->assertOk()->json('data.0.posts.*.title'));

        DB::enableQueryLog();

        self::assertSame(['By Alice'], $this->actingAs($alice)->getJson(self::POSTS_URL)->assertOk()->json('data.0.posts.*.title'));
        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that a relation to a tenant-scoped model is cached per tenant, and
     * that a repeat is served from the cache.
     *
     * @return void
     */
    public function testRelatedModelGlobalScopeIsCachedPerTenant(): void
    {
        $tenants = [];

        foreach (['one', 'two'] as $name) {
            $tenants[$name] = Organization::create(['name' => $name, 'slug' => $name])->id;

            TenantUser::create(['name' => $name, 'email' => $name . '@example.com', 'organization_id' => $tenants[$name]]);
        }

        self::assertSame([['one'], []], $this->memberNamesAs($tenants['one']));
        self::assertSame([[], ['two']], $this->memberNamesAs($tenants['two']));

        DB::enableQueryLog();

        self::assertSame([['one'], []], $this->memberNamesAs($tenants['one']));
        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that a read widening an opted-in relation to trashed rows is cached
     * apart from the default read and served from the cache on repeat.
     *
     * @return void
     */
    public function testRelationWidenedToTrashedRowsIsCachedApartFromTheDefault(): void
    {
        $author = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->seedArticle($author->id, 'Live', 'live');
        $this->seedArticle($author->id, 'Removed', 'removed')->delete();

        self::assertSame(['Live'], $this->getJson('/articles')->assertOk()->json('data.0.siblingArticles.*.title'));
        self::assertSame(['Live', 'Removed'], $this->getJson('/articles?trashed=with')->assertOk()->json('data.0.siblingArticles.*.title'));

        DB::enableQueryLog();

        self::assertSame(['Live', 'Removed'], $this->getJson('/articles?trashed=with')->assertOk()->json('data.0.siblingArticles.*.title'));
        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that a relation whose trashed gate differs per caller is cached per
     * caller while the root query stays the same.
     *
     * @return void
     */
    public function testRelationTrashedGateIsCachedPerCaller(): void
    {
        Route::middleware(ParseApiQuery::class)->get('/articles', function (CacheableArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->usingResource(HeaderGatedSiblingsArticleResource::class)->withApiCriteria()->get(); // @phpstan-ignore staticMethod.dynamicCall

            return new ApiResourceCollection($articles, HeaderGatedSiblingsArticleResource::class);
        });

        $author = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->seedArticle($author->id, 'Live', 'live');
        $this->seedArticle($author->id, 'Removed', 'removed')->delete();

        $open = $this->getJson('/articles?trashed=with', [HeaderGatedArticleResource::HEADER => '1'])->assertOk();

        self::assertSame(['Live', 'Removed'], $open->json('data.0.siblingArticles.*.title'));
        self::assertSame(['Live'], $this->getJson('/articles?trashed=with')->assertOk()->json('data.0.siblingArticles.*.title'));
    }

    /**
     * Read each organization's member names as the given tenant.
     *
     * @param  int  $tenant
     * @return array<int, array<int, string>>
     */
    private function memberNamesAs(int $tenant): array
    {
        app()->instance(CurrentTenant::class, new CurrentTenant($tenant));

        /** @var array<int, array{members: array<int, array{name: string}>}> $organizations */
        $organizations = $this->getJson(self::MEMBERS_URL)->assertOk()->json('data');

        return array_map(static fn (array $organization): array => array_column($organization['members'], 'name'), $organizations);
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
