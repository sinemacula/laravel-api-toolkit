<?php

declare(strict_types = 1);

namespace Tests\Integration\Resources;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Http\Resources\ResourceMetadataService;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\EagerLoadApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationColumnNarrower;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate;
use SineMacula\ApiToolkit\Schema\SafetySetDeriver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\AggregatingUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\SchemaCountingUserResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Integration tests for ApiResource resolution with real models.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
final class ApiResourceIntegrationTest extends TestCase
{
    /** @var string The route URI used to exercise the test endpoint. */
    private const string TEST_URL = '/test';

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [AggregatingUserResource::class, OrganizationResource::class, UserResource::class]);

        SchemaCompiler::clearCache();

        $this->seedData();
    }

    /**
     * Test that UserResource resolves with default fields.
     *
     * @return void
     */
    public function testUserResourceResolvesWithDefaultFields(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
        ApiQuery::parse($request);

        $user = User::first();

        $resource = new UserResource($user);
        $data     = $resource->resolve();

        self::assertArrayHasKey('_type', $data);
        self::assertSame('users', $data['_type']);
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('name', $data);
        self::assertArrayHasKey('email', $data);
    }

    /**
     * Test that UserResource resolves with specific requested fields.
     *
     * @return void
     */
    public function testUserResourceResolvesWithSpecificRequestedFields(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'name,status'],
        ]);
        ApiQuery::parse($request);

        $user = User::first();

        $resource = new UserResource($user);
        $data     = $resource->resolve();

        self::assertArrayHasKey('name', $data);
        self::assertArrayHasKey('status', $data);
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('_type', $data);
    }

    /**
     * Test that UserResource resolves nested organization relation.
     *
     * @return void
     */
    public function testUserResourceResolvesNestedOrganizationRelation(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'name,organization'],
        ]);
        ApiQuery::parse($request);

        $user = User::with('organization')->first();

        $resource = new UserResource($user);
        $data     = $resource->resolve();

        self::assertArrayHasKey('organization', $data);
        self::assertInstanceOf(OrganizationResource::class, $data['organization']);

        $orgData = $data['organization']->resolve();

        self::assertArrayHasKey('name', $orgData);
    }

    /**
     * Test that every item of a resource collection renders the same keys.
     *
     * @return void
     */
    public function testResourceCollectionResolvesCorrectly(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => ['users' => 'name,organization']]);
        ApiQuery::parse($request);

        $data = UserResource::collection(User::query()->orderBy('id')->get())->resolve();

        self::assertSame([['_type', 'id', 'name'], ['_type', 'id', 'name']], array_map(array_keys(...), $data));
    }

    /**
     * Test that rendering a 500-row page with a relation builds the schema
     * once.
     *
     * @return void
     */
    public function testRenderingAPageBuildsTheSchemaOnce(): void
    {
        /** @var \Tests\Fixtures\Models\Organization $organization */
        $organization = Organization::query()->firstOrFail();

        DB::table('users')->insert(array_map(static fn (int $index): array => [
            'name'            => 'User ' . $index,
            'email'           => 'user' . $index . '@example.com',
            'organization_id' => $organization->id,
        ], range(1, 498)));

        ApiQuery::parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => [SchemaCountingUserResource::RESOURCE_TYPE => 'name,organization'],
        ]));

        SchemaCountingUserResource::$schemaCalls = 0;

        $data = SchemaCountingUserResource::collection(User::with('organization')->get())->resolve();

        self::assertCount(500, $data);
        self::assertSame(1, SchemaCountingUserResource::$schemaCalls);
    }

    /**
     * Test that eager loading map is built correctly.
     *
     * @return void
     */
    public function testEagerLoadingMapIsBuiltCorrectly(): void
    {
        $fields = ['name', 'organization', 'posts'];

        $map = UserResource::eagerLoadMapFor($fields);

        // The default limit bounds every resource relation, so each is scoped
        self::assertInstanceOf(\Closure::class, $map['organization'] ?? null);
        self::assertInstanceOf(\Closure::class, $map['posts'] ?? null);
    }

    /**
     * Test that a requested count is included and replaces the default sum.
     *
     * @return void
     */
    public function testARequestedCountReplacesTheDefaultSum(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields'     => ['users' => 'name,aggregates'],
            'aggregates' => ['users' => 'posts_count'],
        ]);
        ApiQuery::parse($request);

        $alice = User::query()->where('name', 'Alice')->firstOrFail();

        $data = (new UserResource($alice, true))->resolve();

        self::assertSame(['posts_count' => 2], $data['aggregates']);
        self::assertArrayNotHasKey('aggregate_posts_sum_id', $alice->getAttributes());
    }

    /**
     * Test that every function resolves identically through withAggregate() on
     * the query and through loadAggregate() on a loaded model.
     *
     * @return void
     */
    public function testEveryFunctionResolvesIdenticallyOnTheQueryAndLoadPaths(): void
    {
        ApiQuery::parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb()));

        $alice = User::query()->where('name', 'Alice')->firstOrFail();
        $ids   = $alice->posts()->orderBy('id')->pluck('id')->all();

        Post::query()->whereKey($ids[0])->update(['created_at' => '2026-03-01 09:30:00']);
        Post::query()->whereKey($ids[1])->update(['created_at' => '2026-01-01 08:00:00']);

        $posts = $alice->posts()->get();

        $queryPath = array_map(static fn (User $user): array => (new AggregatingUserResource($user))->resolve(), $this->usersWithAggregates());
        $loadPath  = array_map(static fn (User $user): array => (new AggregatingUserResource($user, true))->resolve(), $this->users());

        self::assertSame(json_encode($queryPath), json_encode($loadPath));

        $aggregates = $queryPath[0]['aggregates'];

        self::assertIsArray($aggregates);
        self::assertSame(
            ['posts_count', 'has_published_posts', 'posts_sum_id', 'posts_avg_id', 'posts_min_created_at', 'last_posted_at'],
            array_keys($aggregates),
        );
        self::assertSame(2, $aggregates['posts_count']);
        self::assertTrue($aggregates['has_published_posts']);
        self::assertSame((float) $posts->sum('id'), $aggregates['posts_sum_id']); // @phpstan-ignore cast.double
        self::assertSame((float) $posts->avg('id'), $aggregates['posts_avg_id']); // @phpstan-ignore cast.double
        self::assertInstanceOf(\DateTimeInterface::class, $aggregates['posts_min_created_at']);
        self::assertInstanceOf(\DateTimeInterface::class, $aggregates['last_posted_at']);
        self::assertSame('2026-01-01 08:00:00', $aggregates['posts_min_created_at']->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-01 09:30:00', $aggregates['last_posted_at']->format('Y-m-d H:i:s'));

        // Bob has no posts, so only the count and the existence check render
        self::assertSame(['posts_count' => 0, 'has_published_posts' => false], $queryPath[1]['aggregates']);
    }

    /**
     * Test that a constrained existence check is true only for the rows whose
     * related rows match the constraint.
     *
     * @return void
     */
    public function testAConstrainedExistsIsTrueOnlyForMatchingRows(): void
    {
        ApiQuery::parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'aggregates' => ['aggregating_users' => 'posts_count,has_published_posts'],
        ]));

        $bob = User::query()->where('name', 'Bob')->firstOrFail();

        Post::create(['user_id' => $bob->id, 'title' => 'Draft', 'body' => 'Content', 'published' => false]);

        $flags = array_map(static fn (User $user): mixed => (new AggregatingUserResource($user))->resolve()['aggregates'], $this->usersWithAggregates());

        self::assertSame([
            ['posts_count' => 2, 'has_published_posts' => true],
            ['posts_count' => 1, 'has_published_posts' => false],
        ], $flags);
    }

    /**
     * Test that each default key is the attribute Laravel itself aliases the
     * aggregate under.
     *
     * @return void
     */
    public function testDefaultKeysMatchLaravelsOwnAliases(): void
    {
        $sql = User::query()->withCount('posts')->withExists('posts')->withSum('posts', 'id')->withMax('posts', 'created_at')->toSql();

        $keys = [
            AggregateFunction::COUNT->defaultKey('posts', null),
            AggregateFunction::EXISTS->defaultKey('posts', null),
            AggregateFunction::SUM->defaultKey('posts', 'id'),
            AggregateFunction::MAX->defaultKey('posts', 'created_at'),
        ];

        foreach ($keys as $key) {
            self::assertStringContainsString(sprintf('as "%s"', $key), str_replace('`', '"', $sql));
        }
    }

    /**
     * Read the users, in key order, through a query carrying the planned
     * aggregates of the fixture.
     *
     * @return array<int, \Tests\Fixtures\Models\User>
     */
    private function usersWithAggregates(): array
    {
        assert($this->app !== null);

        $introspector = $this->app->make(SchemaIntrospectionProvider::class);
        $gate         = new RelationTrashedGate($introspector, new Request, []);
        $narrower     = new RelationColumnNarrower(new SafetySetDeriver($introspector), $introspector);
        $query        = User::query();

        (new EagerLoadApplier)->apply($query, new ResourceMetadataService, AggregatingUserResource::class, AggregatingUserResource::getResourceType(), $gate, $narrower);

        return $query->get()->sortBy('id')->values()->all();
    }

    /**
     * Read the users, in key order, without any aggregate.
     *
     * @return array<int, \Tests\Fixtures\Models\User>
     */
    private function users(): array
    {
        return User::query()->get()->sortBy('id')->values()->all();
    }

    /**
     * Seed the database with test data.
     *
     * @return void
     */
    private function seedData(): void
    {
        $org = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp']);

        $alice = User::create([
            'name'            => 'Alice',
            'email'           => 'alice@example.com',
            'status'          => 'active',
            'organization_id' => $org->id,
        ]);

        User::create([
            'name'            => 'Bob',
            'email'           => 'bob@example.com',
            'status'          => 'active',
            'organization_id' => $org->id,
        ]);

        Post::create(['user_id' => $alice->id, 'title' => 'First Post', 'body' => 'Content', 'published' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Second Post', 'body' => 'Content', 'published' => false]);
    }
}
