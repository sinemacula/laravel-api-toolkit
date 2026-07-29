<?php

declare(strict_types = 1);

namespace Tests\Feature\Routing;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Actors\ActorUser;
use Tests\Fixtures\Controllers\SkipAuthorizationController;
use Tests\Fixtures\Controllers\TestingAuthorizedController;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Policies\UserPolicy;
use Tests\TestCase;

/**
 * Feature tests for the authorized controller guard through the HTTP kernel.
 *
 * Dispatches real requests to an authorized controller backed by a policy: an
 * action outside the guard exclusions is gated by its ability and, when denied,
 * renders the toolkit forbidden envelope, while an excluded action bypasses the
 * guard entirely. Model-bound actions resolve the route binding and authorize
 * the resolved instance, proving the parameter-based gate end to end.
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

        Route::apiResource('users', TestingAuthorizedController::class)
            ->middleware(SubstituteBindings::class);
    }

    /**
     * Test that an excluded action bypasses the authorization guard.
     *
     * @return void
     */
    public function testExcludedActionBypassesTheGuard(): void
    {
        $actor = ActorUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $response = $this->actingAs($actor)->getJson('/users');

        $response->assertOk();
    }

    /**
     * Test that an action marked #[SkipAuthorization] bypasses the guard over a
     * real request, succeeding with no gate applied even though the view
     * ability has no granting policy method and would otherwise be denied.
     *
     * @return void
     */
    public function testSkipAuthorizationActionBypassesTheGuardOverHttp(): void
    {
        Route::apiResource('skippers', SkipAuthorizationController::class)
            ->middleware(SubstituteBindings::class);

        $actor = ActorUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user  = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $response = $this->actingAs($actor)->getJson('/skippers/' . $user->id);

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

        $response = $this->actingAs($actor)->postJson('/users', []);

        $response->assertStatus(403);
        $response->assertJsonPath('error.status', 403);
        $response->assertJsonPath('error.code', 10102);
    }

    /**
     * Test that a denied model-bound ability renders the forbidden envelope.
     *
     * @return void
     */
    public function testDeniedModelBoundAbilityRendersForbiddenEnvelope(): void
    {
        $actor = ActorUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user  = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $response = $this->actingAs($actor)->putJson('/users/' . $user->id, []);

        $response->assertStatus(403);
        $response->assertJsonPath('error.status', 403);
    }

    /**
     * Test that an allowed model-bound ability passes through to the action.
     *
     * @return void
     */
    public function testAllowedModelBoundAbilityPasses(): void
    {
        $actor = ActorUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user  = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $response = $this->actingAs($actor)->deleteJson('/users/' . $user->id);

        $response->assertOk();
    }
}
