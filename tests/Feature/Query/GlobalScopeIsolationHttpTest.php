<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\RelationWindow;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Middleware\BindCurrentTenant;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\TenantPost;
use Tests\Fixtures\Models\TenantUser;
use Tests\Fixtures\Repositories\TenantUserRepository;
use Tests\Fixtures\Resources\TenantPostResource;
use Tests\Fixtures\Resources\TenantUserResource;
use Tests\TestCase;

/**
 * Feature tests proving a tenant global scope on the root and the related model
 * holds on every uncached read path.
 *
 * The reading tenant's users carry posts of another tenant, so a path that
 * dropped the related scope would render, count or match them.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
#[CoversClass(RelationWindow::class)]
final class GlobalScopeIsolationHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var string The selection embedding each user's posts and every aggregate over them */
    private const string POSTS_AND_AGGREGATES = 'fields[tenant_users]=name,posts,aggregates&fields[tenant_posts]=title'
        . '&aggregates[tenant_users]=posts_count,posts_exists,posts_sum_words';

    /** @var array<string, int> The key of each tenant's organization, by tenant name */
    private array $tenants = [];

    /** @var array<string, int> The key of each user, by name */
    private array $users = [];

    /** @var array<string, int> The key of each post, by title */
    private array $posts = [];

    /**
     * Route the tenant-scoped users and seed two tenants whose posts cross.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [TenantUserResource::class, TenantPostResource::class]);
        Config::set('api-toolkit.resources.resource_map', [TenantUser::class => TenantUserResource::class]);

        if (DB::connection()->getDriverName() === 'sqlite') {
            Config::set('api-toolkit.search.unverified_connections', [DB::getDefaultConnection()]);
        }

        $this->registerRoutes();
        $this->seedTenants();
    }

    /**
     * Test that the index lists, counts and totals only the tenant's users.
     *
     * @return void
     */
    public function testIndexListsAndTotalsOnlyTheTenantsUsers(): void
    {
        $response = $this->readAs('one', '/users');

        self::assertSame(['alice', 'bob'], $response->json('data.*.name'));
        $response->assertJsonPath('meta.total', 2);
        $response->assertHeader('Total-Count', '2');
    }

    /**
     * Provide the relation limit of a bounded and an unbounded embed.
     *
     * @return iterable<string, array{int}>
     */
    public static function relationLimits(): iterable
    {
        yield 'bounded' => [1];
        yield 'unbounded' => [0];
    }

    /**
     * Test that embedded posts and every aggregate over them see only the
     * tenant's posts, and a bounded embed is not marked cut by hidden rows.
     *
     * @param  int  $limit
     * @return void
     */
    #[DataProvider('relationLimits')]
    public function testEmbeddedPostsAndAggregatesSeeOnlyTheTenantsPosts(int $limit): void
    {
        Config::set('api-toolkit.resources.relation_limit', $limit);

        $this->readAs('one', '/users?' . self::POSTS_AND_AGGREGATES)->assertJsonPath('data', $this->expectedUsers());
    }

    /**
     * Test that a route-bound user renders only the tenant's posts, and another
     * tenant's user is not found.
     *
     * @return void
     */
    public function testShowRendersOnlyTheTenantsPostsAndHidesAnotherTenantsUser(): void
    {
        $this->readAs('one', '/users/' . $this->users['alice'] . '?' . self::POSTS_AND_AGGREGATES)->assertJsonPath('data', $this->expectedUsers()[0]);

        $this->withHeader(BindCurrentTenant::HEADER, (string) $this->tenants['one'])
            ->getJson('/users/' . $this->users['carol'])
            ->assertNotFound();
    }

    /**
     * Provide filters over the posts relation with the users each matches.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function relationFilters(): iterable
    {
        yield 'nested filter on a hidden post' => ['{"posts":{"title":{"$eq":"leaked"}}}', []];
        yield 'nested filter on a visible post' => ['{"posts":{"title":{"$eq":"alpha"}}}', ['alice']];
        yield 'has posts' => ['{"$has":["posts"]}', ['alice']];
        yield 'has no posts' => ['{"$hasnt":["posts"]}', ['bob']];
        yield 'has a hidden post' => ['{"$has":{"posts":{"title":{"$eq":"foreign"}}}}', []];
    }

    /**
     * Test that a relation filter matches only through the tenant's posts.
     *
     * @param  string  $filters
     * @param  list<string>  $names
     * @return void
     */
    #[DataProvider('relationFilters')]
    public function testRelationFiltersMatchOnlyThroughTheTenantsPosts(string $filters, array $names): void
    {
        self::assertSame($names, $this->readAs('one', '/users?filters=' . urlencode($filters))->json('data.*.name'));
    }

    /**
     * Test that a search never matches another tenant's user.
     *
     * @return void
     */
    public function testSearchMatchesOnlyTheTenantsUsers(): void
    {
        self::assertSame([], $this->readAs('one', '/users?search=carol@example.com')->json('data'));
        self::assertSame(['alice'], $this->readAs('one', '/users?search=alice@example.com')->json('data.*.name'));
    }

    /**
     * Test that a full cursor walk visits exactly the tenant's users.
     *
     * @return void
     */
    public function testCursorWalkVisitsOnlyTheTenantsUsers(): void
    {
        $names = [];
        $url   = '/users?pagination=cursor&limit=1';

        while (is_string($url)) {

            $page  = $this->readAs('one', $url);
            $names = [...$names, ...(array) $page->json('data.*.name')];
            $url   = $page->json('links.next');
        }

        self::assertSame(['alice', 'bob'], $names);
    }

    /**
     * Test that the other tenant reads its own user and posts, not the first
     * tenant's.
     *
     * @return void
     */
    public function testTheOtherTenantReadsOnlyItsOwnRows(): void
    {
        $response = $this->readAs('two', '/users?' . self::POSTS_AND_AGGREGATES);

        self::assertSame(['carol'], $response->json('data.*.name'));
        self::assertSame(['carols'], $response->json('data.0.posts.*.title'));
        $response->assertJsonPath('data.0.aggregates', ['posts_count' => 1, 'posts_exists' => true, 'posts_sum_words' => 7]);
    }

    /**
     * Define the database migrations.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::dropIfExists('tenant_posts');
        Schema::create('tenant_posts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->unsignedInteger('words');
            $table->timestamps();

            $table->index(['organization_id', 'user_id']);
        });
    }

    /**
     * Route the users index and show behind the middleware binding the tenant
     * the request names.
     *
     * @return void
     */
    private function registerRoutes(): void
    {
        Route::middleware([BindCurrentTenant::class, ParseApiQuery::class, SubstituteBindings::class])->group(static function (): void {

            Route::get('/users', static fn (TenantUserRepository $users): ApiResourceCollection => new ApiResourceCollection(
                $users->withApiCriteria()->paginate(),
                TenantUserResource::class,
            ));

            Route::get('/users/{user}', static fn (TenantUser $user): TenantUserResource => new TenantUserResource($user, loadMissing: true));
        });
    }

    /**
     * Seed tenant one's alice and bob and tenant two's carol, attaching tenant
     * two's posts to tenant one's users.
     *
     * @return void
     */
    private function seedTenants(): void
    {
        foreach (['one', 'two'] as $tenant) {
            $this->tenants[$tenant] = Organization::create(['name' => $tenant, 'slug' => $tenant])->id;
        }

        foreach (['alice' => 'one', 'bob' => 'one', 'carol' => 'two'] as $name => $tenant) {
            $this->users[$name] = TenantUser::create(['name' => $name, 'email' => $name . '@example.com', 'organization_id' => $this->tenants[$tenant]])->id;
        }

        $posts = [
            ['one', 'alice', 'alpha', 3],
            ['two', 'alice', 'leaked', 100],
            ['two', 'bob', 'foreign', 50],
            ['two', 'carol', 'carols', 7],
        ];

        foreach ($posts as [$tenant, $author, $title, $words]) {
            $this->posts[$title] = TenantPost::create(['organization_id' => $this->tenants[$tenant], 'user_id' => $this->users[$author], 'title' => $title, 'words' => $words])->id;
        }
    }

    /**
     * Return tenant one's users as the posts and aggregates selection renders
     * them.
     *
     * @return list<array<string, mixed>>
     */
    private function expectedUsers(): array
    {
        return [
            [
                '_type'      => 'tenant_users',
                'id'         => $this->users['alice'],
                'aggregates' => ['posts_count' => 1, 'posts_exists' => true, 'posts_sum_words' => 3],
                'name'       => 'alice',
                'posts'      => [['_type' => 'tenant_posts', 'id' => $this->posts['alpha'], 'title' => 'alpha']],
            ],
            [
                '_type'      => 'tenant_users',
                'id'         => $this->users['bob'],
                'aggregates' => ['posts_count' => 0, 'posts_exists' => false],
                'name'       => 'bob',
                'posts'      => [],
            ],
        ];
    }

    /**
     * Send a GET as the given tenant and assert it succeeded.
     *
     * @param  string  $tenant
     * @param  string  $uri
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function readAs(string $tenant, string $uri): TestResponse
    {
        return $this->withHeader(BindCurrentTenant::HEADER, (string) $this->tenants[$tenant])->getJson($uri)->assertOk();
    }
}
