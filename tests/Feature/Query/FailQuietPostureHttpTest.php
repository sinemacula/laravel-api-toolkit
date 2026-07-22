<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\FilterableUserResource;
use Tests\TestCase;

/**
 * Feature test for the fail-quiet allowlist posture over HTTP.
 *
 * With reject_undeclared disabled the allowlist posture drops an undeclared key
 * rather than rejecting it: no ValidationException surfaces, so a real request
 * carrying an undeclared filter returns 200 with the full unfiltered set rather
 * than the fail-closed 422 envelope. This proves the dropped-key path travels
 * through the kernel without escaping as a client error.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurface::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(ApiResourceCollection::class)]
final class FailQuietPostureHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test under the allowlist posture with fail-quiet rejection
     * and seeded rows.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.repositories.reject_undeclared', false);

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);
        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'status' => 'inactive']);
    }

    /**
     * Test that an undeclared filter key is dropped and returns the full set
     * with a 200 status rather than a validation error.
     *
     * @return void
     */
    public function testUndeclaredFilterIsDroppedAndReturnsFullSet(): void
    {
        $filters = json_encode(['status' => 'active']);

        $response = $this->getJson('/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.total', 3);
    }
}
