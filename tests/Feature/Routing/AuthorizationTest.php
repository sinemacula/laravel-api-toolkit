<?php

declare(strict_types = 1);

namespace Tests\Feature\Routing;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Middleware\EnsureActionIsAuthorized;
use SineMacula\ApiToolkit\Http\Routing\AuthorizedController;
use Tests\Concerns\CreatesDeferredWriteTargets;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Actors\ActorUser;
use Tests\Fixtures\Controllers\ArticleController;
use Tests\Fixtures\Controllers\ClassAuthorizedController;
use Tests\Fixtures\Controllers\CustomActionController;
use Tests\Fixtures\Controllers\InvokableAuthorizedController;
use Tests\Fixtures\Controllers\LedgerEntryController;
use Tests\Fixtures\Controllers\SkipAuthorizationController;
use Tests\Fixtures\Controllers\TestingAuthorizedController;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\LedgerEntry;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Policies\ArticlePolicy;
use Tests\Fixtures\Policies\LedgerEntryPolicy;
use Tests\Fixtures\Policies\UserPolicy;
use Tests\TestCase;

/**
 * Feature tests for the authorized controller guard through the HTTP kernel.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AuthorizedController::class)]
#[CoversClass(EnsureActionIsAuthorized::class)]
#[CoversClass(ApiExceptionHandler::class)]
final class AuthorizationTest extends TestCase
{
    use CreatesDeferredWriteTargets, RegistersApiExceptionHandler;

    /** The message refusing the custom action that declares no authorization */
    private const string UNDECLARED_PUBLISH = 'The [' . CustomActionController::class . '@publish] action declares no authorization: '
        . 'gate it with #[Authorize], or mark it #[CustomAuthorization] or #[SkipAuthorization]';

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
        Gate::policy(Article::class, ArticlePolicy::class);
        Gate::policy(LedgerEntry::class, LedgerEntryPolicy::class);

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
        $response = $this->actingAs($this->actor())->getJson('/users');

        $response->assertOk();
    }

    /**
     * Test that an action marked #[SkipAuthorization] bypasses the guard over a
     * real request.
     *
     * @return void
     */
    public function testSkipAuthorizationActionBypassesTheGuardOverHttp(): void
    {
        Route::apiResource('skippers', SkipAuthorizationController::class)
            ->middleware(SubstituteBindings::class);

        $response = $this->actingAs($this->actor())->getJson('/skippers/' . $this->user()->id);

        $response->assertOk();
    }

    /**
     * Test that a gate whose parameter the route does not bind receives no
     * model and denies an ability the policy allows for the bound model.
     *
     * @return void
     */
    public function testMismatchedParameterFailsClosed(): void
    {
        Route::delete('members/{member}', [TestingAuthorizedController::class, 'destroy'])
            ->middleware(SubstituteBindings::class);

        $response = $this->actingAs($this->actor())->deleteJson('/members/' . $this->user()->id);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 10102);
    }

    /**
     * Test that a denied ability renders the toolkit forbidden envelope.
     *
     * @return void
     */
    public function testDeniedAbilityRendersForbiddenEnvelope(): void
    {
        $response = $this->actingAs($this->actor())->postJson('/users', []);

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
        $response = $this->actingAs($this->actor())->putJson('/users/' . $this->user()->id, []);

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
        $response = $this->actingAs($this->actor())->deleteJson('/users/' . $this->user()->id);

        $response->assertOk();
    }

    /**
     * Test that a guest is forbidden rather than unauthenticated, since the
     * controller authorizes but does not authenticate.
     *
     * @return void
     */
    public function testGuestIsForbiddenFromAnAllowedAbility(): void
    {
        $response = $this->deleteJson('/users/' . $this->user()->id);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 10102);
    }

    /**
     * Test that a guest behind auth middleware is unauthenticated before the
     * gate runs.
     *
     * @return void
     */
    public function testGuestBehindAuthIsUnauthenticated(): void
    {
        Route::delete('accounts/{user}', [TestingAuthorizedController::class, 'destroy'])
            ->middleware(['auth', SubstituteBindings::class]);

        $response = $this->deleteJson('/accounts/' . $this->user()->id);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 10101);
    }

    /**
     * Test that a multi-word model's derived parameter matches the one the
     * resource route generates, so the bound model reaches the policy.
     *
     * @return void
     */
    public function testMultiWordModelReachesThePolicyThroughItsDerivedParameter(): void
    {
        Route::apiResource('ledger-entries', LedgerEntryController::class)
            ->only('update')
            ->middleware(SubstituteBindings::class);

        $actor  = $this->actor();
        $open   = LedgerEntry::query()->insertGetId(['label' => 'open']);
        $closed = LedgerEntry::query()->insertGetId(['label' => 'closed']);

        $this->actingAs($actor)->putJson('/ledger-entries/' . $open, [])->assertOk();
        $this->actingAs($actor)->putJson('/ledger-entries/' . $closed, [])->assertStatus(403);
    }

    /**
     * Test that a routed action declaring no authorization is refused as a
     * reported server error before its body runs, without leaking the reason.
     *
     * @return void
     */
    public function testUngatedCustomActionIsRefusedAsAServerError(): void
    {
        Exceptions::fake();
        Config::set('api-toolkit.exceptions.include_debug_info', false);

        Route::post('users/{user}/publish', [CustomActionController::class, 'publish'])
            ->middleware(SubstituteBindings::class);

        $user = $this->user();

        $response = $this->actingAs($this->actor())->postJson('/users/' . $user->id . '/publish');

        $response->assertStatus(500);
        $response->assertJsonPath('error.code', 10001);
        $response->assertJsonMissingPath('error.meta');
        self::assertSame('Bob', $user->fresh()?->name);

        Exceptions::assertReported(static fn (\LogicException $exception): bool => $exception->getMessage() === self::UNDECLARED_PUBLISH);
    }

    /**
     * Test that a custom action gated by #[Authorize] answers as its policy
     * decides.
     *
     * @return void
     */
    public function testAuthorizeAttributeGatesACustomAction(): void
    {
        Route::post('users/{user}/approve', [CustomActionController::class, 'approve'])
            ->middleware(SubstituteBindings::class);
        Route::post('users/{user}/archive', [CustomActionController::class, 'archive'])
            ->middleware(SubstituteBindings::class);

        $actor = $this->actor();
        $user  = $this->user();

        $this->actingAs($actor)->postJson('/users/' . $user->id . '/approve')->assertStatus(403);
        $this->actingAs($actor)->postJson('/users/' . $user->id . '/archive')->assertOk();
    }

    /**
     * Test that a #[CustomAuthorization] action runs and denies through its own
     * check.
     *
     * @return void
     */
    public function testCustomAuthorizationActionDeniesThroughItsOwnCheck(): void
    {
        Route::post('users/{user}/lock', [CustomActionController::class, 'lock'])
            ->middleware(SubstituteBindings::class);

        $response = $this->actingAs($this->actor())->postJson('/users/' . $this->user()->id . '/lock');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 10102);
    }

    /**
     * Test that a mapped action whose gate the route removes is refused before
     * its body runs.
     *
     * @return void
     */
    public function testMappedActionWithoutItsGateIsRefused(): void
    {
        Route::put('members/{user}', [CustomActionController::class, 'update'])
            ->middleware(SubstituteBindings::class)
            ->withoutMiddleware('can');

        $user = $this->user();

        $this->actingAs($this->actor())->putJson('/members/' . $user->id, [])->assertStatus(500);

        self::assertSame('Bob', $user->fresh()?->name);
    }

    /**
     * Test that class-level #[Authorize] attributes, declared or inherited,
     * gate the actions they cover and leave the rest refused.
     *
     * @return void
     */
    public function testClassLevelAuthorizeAttributesGateTheActionsTheyCover(): void
    {
        Route::post('staff/{user}/archive', [ClassAuthorizedController::class, 'archive'])
            ->middleware(SubstituteBindings::class);
        Route::post('staff/{user}/approve', [ClassAuthorizedController::class, 'approve'])
            ->middleware(SubstituteBindings::class);
        Route::post('staff/{user}/publish', [ClassAuthorizedController::class, 'publish'])
            ->middleware(SubstituteBindings::class);

        $actor = $this->actor();
        $user  = $this->user();

        $this->actingAs($actor)->postJson('/staff/' . $user->id . '/archive')->assertOk();
        $this->actingAs($actor)->postJson('/staff/' . $user->id . '/approve')->assertStatus(403);
        $this->actingAs($actor)->postJson('/staff/' . $user->id . '/publish')->assertStatus(500);
    }

    /**
     * Test that an inherited #[SkipAuthorization] action is served.
     *
     * @return void
     */
    public function testInheritedSkipAuthorizationActionIsServed(): void
    {
        Route::get('staff/ping', [ClassAuthorizedController::class, 'ping']);

        $this->actingAs($this->actor())->getJson('/staff/ping')->assertOk();
    }

    /**
     * Test that a single-action controller declaring no authorization is
     * refused.
     *
     * @return void
     */
    public function testInvokableControllerDeclaringNoAuthorizationIsRefused(): void
    {
        Route::post('users/{user}/notify', InvokableAuthorizedController::class)
            ->middleware(SubstituteBindings::class);

        $this->actingAs($this->actor())->postJson('/users/' . $this->user()->id . '/notify')->assertStatus(500);
    }

    /**
     * Test that restoring a trashed row is gated by the restore ability.
     *
     * @return void
     */
    public function testRestoreIsGatedByTheRestoreAbility(): void
    {
        Route::post('articles/{article}/restore', [ArticleController::class, 'restore'])
            ->middleware(SubstituteBindings::class)
            ->withTrashed();

        $author = $this->actor();
        $denied = $this->trashedArticle($author->id + 1, 'denied');
        $owned  = $this->trashedArticle($author->id, 'owned');

        $this->actingAs($author)->postJson('/articles/' . $denied->id . '/restore')->assertStatus(403);
        $this->actingAs($author)->postJson('/articles/' . $owned->id . '/restore')->assertOk();

        self::assertTrue($denied->fresh()?->trashed());
        self::assertFalse($owned->fresh()?->trashed());
    }

    /**
     * Test that permanently deleting a trashed row is gated by the forceDelete
     * ability.
     *
     * @return void
     */
    public function testForceDestroyIsGatedByTheForceDeleteAbility(): void
    {
        Route::delete('articles/{article}/force', [ArticleController::class, 'forceDestroy'])
            ->middleware(SubstituteBindings::class)
            ->withTrashed();

        $author = $this->actor();
        $denied = $this->trashedArticle($author->id + 1, 'denied');
        $owned  = $this->trashedArticle($author->id, 'owned');

        $this->actingAs($author)->deleteJson('/articles/' . $denied->id . '/force')->assertStatus(403);
        $this->actingAs($author)->deleteJson('/articles/' . $owned->id . '/force')->assertOk();

        self::assertNotNull(Article::withTrashed()->find($denied->id));
        self::assertNull(Article::withTrashed()->find($owned->id));
    }

    /**
     * Test that a trashed row is not found when the route does not bind trashed
     * rows, before the gate runs.
     *
     * @return void
     */
    public function testTrashedRowIsNotFoundWithoutWithTrashed(): void
    {
        Route::post('articles/{article}/restore', [ArticleController::class, 'restore'])
            ->middleware(SubstituteBindings::class);

        $author  = $this->actor();
        $article = $this->trashedArticle($author->id, 'owned');

        $this->actingAs($author)->postJson('/articles/' . $article->id . '/restore')->assertNotFound();
    }

    /**
     * Define the database migrations.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->createTimestampFixtureTables();
    }

    /**
     * Create the authenticated actor.
     *
     * @return \Tests\Fixtures\Actors\ActorUser
     */
    private function actor(): ActorUser
    {
        return ActorUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    }

    /**
     * Create the user the actions act on.
     *
     * @return \Tests\Fixtures\Models\User
     */
    private function user(): User
    {
        return User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    }

    /**
     * Create a soft-deleted article by the given author.
     *
     * @param  int  $authorId
     * @param  string  $slug
     * @return \Tests\Fixtures\Models\Article
     */
    private function trashedArticle(int $authorId, string $slug): Article
    {
        $article = Article::create(['user_id' => $authorId, 'title' => 'Title', 'slug' => $slug, 'body' => 'Body', 'summary' => 'Summary']);

        $article->delete();

        return $article;
    }
}
