<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
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
 * Feature test proving undeclared-key rejection cannot be configured away by
 * the withdrawn fail-quiet toggle.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurface::class)]
#[CoversClass(FilterApplier::class)]
#[CoversClass(ApiExceptionHandler::class)]
final class LegacyFailQuietConfigHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up the withdrawn fail-quiet toggle in the configuration and seed
     * rows.
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
     * Test that an undeclared filter key is rejected with the 422 envelope.
     *
     * @return void
     */
    public function testUndeclaredFilterIsRejectedRegardlessOfTheWithdrawnToggle(): void
    {
        $filters = json_encode(['status' => 'active']);

        $response = $this->getJson('/users?filters=' . urlencode((string) $filters));

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);

        self::assertArrayHasKey('filters.status', (array) $response->json('error.meta'));
    }
}
