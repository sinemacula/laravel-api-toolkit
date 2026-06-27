<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
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
        // The id and _type are fixed fields
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
     * Test that resource collection resolves correctly.
     *
     * @return void
     */
    public function testResourceCollectionResolvesCorrectly(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
        ApiQuery::parse($request);

        $users = User::all();

        $collection = UserResource::collection($users);
        $data       = $collection->resolve();

        self::assertCount(2, $data);
        self::assertArrayHasKey('_type', $data[0]);
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

        self::assertContains('organization', $map);
        self::assertContains('posts', $map);
    }

    /**
     * Test that counts are included in the response.
     *
     * @return void
     */
    public function testCountsAreIncludedInResponse(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => ['users' => 'name,counts'],
            'counts' => ['users' => 'posts'],
        ]);
        ApiQuery::parse($request);

        $user = User::withCount('posts')->first();

        $resource = new UserResource($user);
        $data     = $resource->resolve();

        self::assertArrayHasKey('counts', $data);
        self::assertArrayHasKey('posts', $data['counts']);
    }

    /**
     * Test that sums and averages are computed via the real Eloquent aggregate
     * path and surfaced in the response.
     *
     * Exercises the full loadSum/loadAvg -> ValueResolver path against the
     * database, so the alias used by EagerLoadPlanner must match the attribute
     * the resolver reads back. Compares against a direct relation aggregate.
     *
     * @return void
     */
    public function testSumsAndAveragesAreIncludedInResponse(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields'   => ['users' => 'name,sums,averages'],
            'sums'     => ['users' => ['posts' => 'id']],
            'averages' => ['users' => ['posts' => 'id']],
        ]);
        ApiQuery::parse($request);

        $alice = User::query()->where('name', 'Alice')->first();

        self::assertNotNull($alice);

        $postIds     = $alice->posts()->get()->pluck('id');
        $expectedSum = (float) $postIds->sum(); // @phpstan-ignore cast.double
        $expectedAvg = (float) $postIds->avg(); // @phpstan-ignore cast.double

        $resource = new UserResource($alice, true);
        $data     = $resource->resolve();

        self::assertArrayHasKey('sums', $data);
        self::assertArrayHasKey('posts_id', $data['sums']);
        self::assertSame($expectedSum, $data['sums']['posts_id']);

        self::assertArrayHasKey('averages', $data);
        self::assertArrayHasKey('posts_id', $data['averages']);
        self::assertSame($expectedAvg, $data['averages']['posts_id']);
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
