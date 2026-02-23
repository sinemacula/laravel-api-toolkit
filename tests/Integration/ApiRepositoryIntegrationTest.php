<?php

namespace Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\TestCase;

/**
 * Integration tests for ApiRepository with a real database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
class ApiRepositoryIntegrationTest extends TestCase
{
    /** @var \Tests\Fixtures\Repositories\UserRepository */
    private UserRepository $repository;

    /**
     * Set up each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(UserRepository::class);

        $this->seedUsers();
    }

    /**
     * Test that paginate returns a paginated collection.
     *
     * @return void
     */
    public function testPaginateReturnsPaginatedCollection(): void
    {
        $request = Request::create('/test', 'GET', ['limit' => '2']);
        ApiQuery::parse($request);

        $results = $this->repository->paginate();

        static::assertCount(2, $results);
        static::assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $results);
    }

    /**
     * Test that setAttributes persists model changes.
     *
     * @return void
     */
    public function testSetAttributesPersistsModelChanges(): void
    {
        $user = User::where('name', 'Alice')->first();

        $reflection = new \ReflectionProperty($this->repository, 'casts');
        $reflection->setValue($this->repository, ['name' => 'string']);

        $result = $this->repository->setAttributes($user, ['name' => 'Alice Updated']);

        static::assertTrue($result);
        $this->assertDatabaseHas('users', ['name' => 'Alice Updated']);
    }

    /**
     * Test that scopeById returns the correct record.
     *
     * @return void
     */
    public function testScopeByIdReturnsCorrectRecord(): void
    {
        $user = User::where('name', 'Alice')->first();

        $result = $this->repository->scopeById($user->id)->first();

        static::assertNotNull($result);
        static::assertSame('Alice', $result->name);
    }

    /**
     * Test that scopeByIds returns the correct records.
     *
     * @return void
     */
    public function testScopeByIdsReturnsCorrectRecords(): void
    {
        $alice = User::where('name', 'Alice')->first();
        $bob   = User::where('name', 'Bob')->first();

        $results = $this->repository->scopeByIds([$alice->id, $bob->id])->get();

        static::assertCount(2, $results);
        static::assertTrue($results->pluck('name')->contains('Alice'));
        static::assertTrue($results->pluck('name')->contains('Bob'));
    }

    /**
     * Test that withApiCriteria applies criteria to query.
     *
     * @return void
     */
    public function testWithApiCriteriaAppliesCriteriaToQuery(): void
    {
        $request = Request::create('/test', 'GET', [
            'filters' => json_encode(['name' => 'Alice']),
        ]);
        ApiQuery::parse($request);

        $results = $this->repository->withApiCriteria()->get();

        static::assertCount(1, $results);
        static::assertSame('Alice', $results->first()->name);
    }

    /**
     * Seed the database with test users.
     *
     * @return void
     */
    private function seedUsers(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);
        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'status' => 'inactive']);
    }
}
