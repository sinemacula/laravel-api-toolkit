<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Actors\ActorUser;
use Tests\Fixtures\Controllers\TestingAuthorizedController;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Policies\UserPolicy;
use Tests\TestCase;

/**
 * Feature tests for the authorized controller guard through the HTTP kernel.
 *
 * Dispatches real requests to an authorized controller backed by a policy: an
 * action outside the guard exclusions is gated by its ability and, when
 * denied, renders the toolkit forbidden envelope, while an excluded action
 * bypasses the guard entirely.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AuthorizedController::class)]
#[CoversClass(ApiExceptionHandler::class)]
final class AuthorizationTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with the policy registered and the controller routed.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Gate::policy(User::class, UserPolicy::class);

        Route::get('/api/users', [TestingAuthorizedController::class, 'index']);
        Route::post('/api/users', [TestingAuthorizedController::class, 'store']);
    }

    /**
     * Test that an excluded action bypasses the authorization guard.
     *
     * @return void
     */
    public function testExcludedActionBypassesTheGuard(): void
    {
        $actor = ActorUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $response = $this->actingAs($actor)->getJson('/api/users');

        $response->assertOk();
    }

    /**
     * Test that a denied ability renders the toolkit forbidden envelope.
     *
     * @return void
     */
    public function testDeniedAbilityRendersForbiddenEnvelope(): void
    {
        $actor = ActorUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $response = $this->actingAs($actor)->postJson('/api/users', []);

        $response->assertStatus(403);
        $response->assertJsonPath('error.status', 403);
        $response->assertJsonPath('error.code', 10102);
    }
}
