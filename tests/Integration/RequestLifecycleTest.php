<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end feature tests covering the full request lifecycle.
 *
 * Exercises the package exactly as a consuming application does: a real
 * HTTP request travels through the ParseApiQuery middleware, the
 * repository applies the parsed criteria, and the resource layer
 * serializes the response payload.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ParseApiQuery::class)]
class RequestLifecycleTest extends TestCase
{
    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // This test verifies the request-lifecycle wiring (middleware ->
        // repository -> resource), not the query posture. Pin the blocklist
        // posture so the route's empty-surface criteria follows the legacy
        // isSearchable contract; the allowlist default is verified end-to-end
        // in QuerySurfaceIntegrationTest.
        Config::set('api-toolkit.repositories.query_posture', QuerySurface::POSTURE_BLOCKLIST);

        Route::middleware(ParseApiQuery::class)->get('/api/users', function (UserRepository $repository) {

            $users = $repository->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, UserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);
        User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'status' => 'inactive']);
    }

    /**
     * Test that an index request returns the default fields for every
     * record.
     *
     * @return void
     */
    public function testIndexReturnsDefaultFieldsForAllRecords(): void
    {
        $response = $this->getJson('/api/users');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('data.0.name', 'Alice');
        $response->assertJsonPath('data.0._type', 'users');

        static::assertArrayHasKey('email', $response->json('data.0'));
    }

    /**
     * Test that field selection via the query string restricts the
     * payload.
     *
     * @return void
     */
    public function testFieldSelectionRestrictsPayload(): void
    {
        $response = $this->getJson('/api/users?fields[users]=name');

        $response->assertOk();

        $record = $response->json('data.0');

        static::assertIsArray($record);
        static::assertArrayHasKey('name', $record);
        static::assertArrayHasKey('id', $record);
        static::assertArrayNotHasKey('email', $record);
    }

    /**
     * Test that filters from the query string are applied to the result
     * set.
     *
     * @return void
     */
    public function testFiltersAreAppliedToResultSet(): void
    {
        $filters = json_encode(['status' => ['$eq' => 'active']]);

        $response = $this->getJson('/api/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    /**
     * Test that ordering from the query string is applied to the result
     * set.
     *
     * @return void
     */
    public function testOrderingIsApplied(): void
    {
        $response = $this->getJson('/api/users?order=name:desc');

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'Carol');
        $response->assertJsonPath('data.2.name', 'Alice');
    }

    /**
     * Test that pagination limits are applied and meta information is
     * present.
     *
     * @return void
     */
    public function testPaginationLimitAndMetaArePresent(): void
    {
        $response = $this->getJson('/api/users?limit=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.count', 2);
        $response->assertJsonPath('meta.total', 3);
    }
}
