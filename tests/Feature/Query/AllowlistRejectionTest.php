<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

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

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

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

        $response = $this->getJson('/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Alice');
    }

    /**
     * Test that an undeclared filter column is rejected as a 422 keyed on the
     * field.
     *
     * @return void
     */
    public function testUndeclaredFilterIsRejectedWithValidationEnvelope(): void
    {
        $filters = json_encode(['status' => 'active']);

        $response = $this->getJson('/users?filters=' . urlencode((string) $filters));

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);
        $response->assertJsonPath('error.code', 10106);

        self::assertArrayHasKey('filters.status', (array) $response->json('error.meta'));
    }

    /**
     * Test that a filterable but non-sortable column is rejected as a sort key.
     *
     * @return void
     */
    public function testUndeclaredSortKeyIsRejectedWithValidationEnvelope(): void
    {
        $response = $this->getJson('/users?order=email:asc');

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);

        self::assertArrayHasKey('order.email', (array) $response->json('error.meta'));
    }
}
