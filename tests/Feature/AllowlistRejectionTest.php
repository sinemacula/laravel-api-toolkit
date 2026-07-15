<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature tests for the allowlist query-surface posture through the kernel.
 *
 * Exercises the secure-by-default posture as a consuming application does: a
 * real request travels through the ParseApiQuery middleware and the repository
 * criteria under the default posture (no posture configured). A declared column
 * is applied and narrows the result set, while an undeclared filter or sort key
 * is rejected and rendered as the toolkit 422 envelope keyed on the offending
 * field.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurface::class)]
#[CoversClass(ApiExceptionHandler::class)]
final class AllowlistRejectionTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test under the default allowlist posture.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        // No query_posture is configured, so the default allowlist posture is
        // in force: only columns FilterableUserResource declares are usable.
        Route::middleware(ParseApiQuery::class)->get('/api/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'inactive']);
    }

    /**
     * Test that a declared filterable column is applied and narrows the result.
     *
     * @return void
     */
    public function testDeclaredFilterIsApplied(): void
    {
        $filters = json_encode(['name' => 'Alice']);

        $response = $this->getJson('/api/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Alice');
    }

    /**
     * Test that an undeclared filter column is rejected as a 422 envelope keyed
     * on the offending field.
     *
     * @return void
     */
    public function testUndeclaredFilterIsRejectedWithValidationEnvelope(): void
    {
        $filters = json_encode(['status' => 'active']);

        $response = $this->getJson('/api/users?filters=' . urlencode((string) $filters));

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);
        $response->assertJsonPath('error.code', 10106);

        self::assertArrayHasKey('filters.status', (array) $response->json('error.meta'));
    }

    /**
     * Test that a column declared filterable but not sortable is rejected when
     * used to order the result set.
     *
     * @return void
     */
    public function testUndeclaredSortKeyIsRejectedWithValidationEnvelope(): void
    {
        $response = $this->getJson('/api/users?order=email:asc');

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);

        self::assertArrayHasKey('order.email', (array) $response->json('error.meta'));
    }
}
