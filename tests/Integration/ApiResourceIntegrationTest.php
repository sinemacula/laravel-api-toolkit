<?php

namespace Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
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
class ApiResourceIntegrationTest extends TestCase
{
    /**
     * Set up each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Clear schema cache between tests
        $reflection = new \ReflectionProperty(ApiResource::class, 'schemaCache');
        $reflection->setValue(null, []);

        $this->seedData();
    }

    /**
     * Test that UserResource resolves with default fields.
     *
     * @return void
     */
    public function testUserResourceResolvesWithDefaultFields(): void
    {
        $request = Request::create('/test', 'GET');
        ApiQuery::parse($request);

        $user = User::first();

        $resource = new UserResource($user);
        $data     = $resource->resolve();

        static::assertArrayHasKey('_type', $data);
        static::assertSame('users', $data['_type']);
        static::assertArrayHasKey('id', $data);
        static::assertArrayHasKey('name', $data);
        static::assertArrayHasKey('email', $data);
    }

    /**
     * Test that UserResource resolves with specific requested fields.
     *
     * @return void
     */
    public function testUserResourceResolvesWithSpecificRequestedFields(): void
    {
        $request = Request::create('/test', 'GET', [
            'fields' => ['users' => 'name,status'],
        ]);
        ApiQuery::parse($request);

        $user = User::first();

        $resource = new UserResource($user);
        $data     = $resource->resolve();

        static::assertArrayHasKey('name', $data);
        static::assertArrayHasKey('status', $data);
        // id and _type are fixed fields
        static::assertArrayHasKey('id', $data);
        static::assertArrayHasKey('_type', $data);
    }

    /**
     * Test that UserResource resolves nested organization relation.
     *
     * @return void
     */
    public function testUserResourceResolvesNestedOrganizationRelation(): void
    {
        $request = Request::create('/test', 'GET', [
            'fields' => ['users' => 'name,organization'],
        ]);
        ApiQuery::parse($request);

        $user = User::with('organization')->first();

        $resource = new UserResource($user);
        $data     = $resource->resolve();

        static::assertArrayHasKey('organization', $data);
        static::assertInstanceOf(\Tests\Fixtures\Resources\OrganizationResource::class, $data['organization']);

        $org_data = $data['organization']->resolve();

        static::assertArrayHasKey('name', $org_data);
    }

    /**
     * Test that resource collection resolves correctly.
     *
     * @return void
     */
    public function testResourceCollectionResolvesCorrectly(): void
    {
        $request = Request::create('/test', 'GET');
        ApiQuery::parse($request);

        $users = User::all();

        $collection = UserResource::collection($users);
        $data       = $collection->resolve();

        static::assertCount(2, $data);
        static::assertArrayHasKey('_type', $data[0]);
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

        static::assertContains('organization', $map);
        static::assertContains('posts', $map);
    }

    /**
     * Test that counts are included in the response.
     *
     * @return void
     */
    public function testCountsAreIncludedInResponse(): void
    {
        $request = Request::create('/test', 'GET', [
            'fields' => ['users' => 'name,counts'],
            'counts' => ['users' => 'posts'],
        ]);
        ApiQuery::parse($request);

        $user = User::withCount('posts')->first();

        $resource = new UserResource($user);
        $data     = $resource->resolve();

        static::assertArrayHasKey('counts', $data);
        static::assertArrayHasKey('posts', $data['counts']);
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

        $bob = User::create([
            'name'            => 'Bob',
            'email'           => 'bob@example.com',
            'status'          => 'active',
            'organization_id' => $org->id,
        ]);

        Post::create(['user_id' => $alice->id, 'title' => 'First Post', 'body' => 'Content', 'published' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Second Post', 'body' => 'Content', 'published' => false]);
    }
}
