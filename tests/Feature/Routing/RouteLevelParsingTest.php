<?php

declare(strict_types = 1);

namespace Tests\Feature\Routing;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use Tests\Fixtures\Controllers\CriteriaComposingController;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests for API criteria on a route that parses the query itself.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiCriteria::class)]
final class RouteLevelParsingTest extends TestCase
{
    /**
     * Test that criteria composed as the controller is built, before the route
     * parses the query, apply the request's filters.
     *
     * @return void
     */
    public function testCriteriaComposedBeforeTheRouteParsesApplyTheRequestsFilters(): void
    {
        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class]);

        Route::middleware(ParseApiQuery::class)->get('/users', [CriteriaComposingController::class, 'index']);

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Charlie', 'email' => 'charlie@example.com', 'status' => 'inactive']);

        $this->getJson('/users?' . http_build_query(['filters' => '{"status":{"$eq":"inactive"}}']))
            ->assertOk()
            ->assertExactJson(['Charlie']);
    }
}
