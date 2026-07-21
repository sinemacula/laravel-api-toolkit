<?php

declare(strict_types = 1);

namespace Tests\Feature;

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
 * Feature tests for the blocklist query-surface posture through the kernel.
 *
 * Under the opt-out blocklist posture a real request is gated by the legacy
 * shape-derived searchable predicate rather than the resource's declared
 * allowlist. An undeclared column that is a real, non-excluded table column is
 * therefore applied and narrows the envelope, while a column named in
 * searchable_exclusions is silently dropped and the full unfiltered set is
 * returned - proving the exclusion set stays honoured even when the resource
 * never declared the column filterable.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurface::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(ApiResourceCollection::class)]
final class BlocklistPostureHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test under the blocklist posture with seeded rows.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.repositories.query_posture', QuerySurface::POSTURE_BLOCKLIST);

        Route::middleware(ParseApiQuery::class)->get('/api/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(FilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, FilterableUserResource::class);
        });

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret', 'status' => 'active']);
        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'password' => 'secret', 'status' => 'inactive']);
    }

    /**
     * Test that an undeclared but real, non-excluded column is applied under
     * the blocklist posture and narrows the envelope.
     *
     * @return void
     */
    public function testUndeclaredColumnIsAppliedUnderBlocklist(): void
    {
        $filters = json_encode(['status' => 'active']);

        $response = $this->getJson('/api/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 2);
    }

    /**
     * Test that a column named in searchable_exclusions is never filterable:
     * the filter is dropped and the full unfiltered set is returned.
     *
     * @return void
     */
    public function testExcludedColumnIsNeverFilterableUnderBlocklist(): void
    {
        $filters = json_encode(['password' => 'no-such-value']);

        $response = $this->getJson('/api/users?filters=' . urlencode((string) $filters));

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.total', 3);
    }
}
