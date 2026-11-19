<?php

declare(strict_types = 1);

namespace Tests\Feature\Services;

use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Services\Actors\AnonymousActor;
use SineMacula\ApiToolkit\Services\Actors\EloquentActor;
use SineMacula\ApiToolkit\Services\Actors\SystemActor;
use SineMacula\ApiToolkit\Services\Input\ArrayInput;
use SineMacula\ApiToolkit\Services\Service;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Services\RetitlePostService;
use Tests\TestCase;

/**
 * Feature tests for a service authorizing its run's actor through the gate.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Service::class)]
#[CoversClass(ServiceRunner::class)]
final class ServiceAuthorizationTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var string The detail the policy denies a non-author with */
    private const string DENIAL = 'Only the author can retitle this post.';

    /** @var string The catalogue detail of a forbidden request */
    private const string FORBIDDEN_DETAIL = 'You do not have the necessary privileges to perform this action';

    /** @var \Tests\Fixtures\Models\User The post's author */
    private User $author;

    /** @var \Tests\Fixtures\Models\Post The post being retitled */
    private Post $post;

    /**
     * Set up the update ability, an authored post and the retitle route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Gate::define('update', static fn (User $user, Post $post): Response => $post->user_id === $user->id
            ? Response::allow()
            : Response::deny(self::DENIAL, 'NOT_AUTHOR'));

        $this->author = User::create(['name' => 'Author', 'email' => 'author@service.test']);
        $this->post   = Post::create(['user_id' => $this->author->id, 'title' => 'Draft', 'body' => 'Body']);

        Route::patch('/posts/{post}', static function (Request $request, string $post): array {

            $user  = $request->user();
            $actor = $user instanceof User ? EloquentActor::for($user) : new AnonymousActor;

            $title = (new RetitlePostService(new ArrayInput(['post' => $post, 'title' => $request->input('title')])))
                ->withContext(ServiceContext::fromRequest($request, $actor))
                ->runOrFail();

            return ['title' => $title];
        });
    }

    /**
     * Test that the gate lets the post's author retitle it.
     *
     * @return void
     */
    public function testAuthorIsAllowed(): void
    {
        $response = $this->actingAs($this->author)->patchJson('/posts/' . $this->post->id, ['title' => 'Published']);

        $response->assertOk();
        $response->assertExactJson(['title' => 'Published']);

        self::assertSame('Published', $this->post->refresh()->title);
    }

    /**
     * Test that the gate's denial of another user renders as a 403 carrying the
     * policy's message and code.
     *
     * @return void
     */
    public function testOtherUserIsDeniedWithThePolicyReason(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@service.test']);

        $response = $this->actingAs($other)->patchJson('/posts/' . $this->post->id, ['title' => 'Hijacked']);

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 10102);
        $response->assertJsonPath('error.detail', self::DENIAL);
        $response->assertJsonPath('error.meta', ['reason' => 'NOT_AUTHOR']);

        self::assertSame('Draft', $this->post->refresh()->title);
    }

    /**
     * Test that an anonymous actor reaches the gate as a guest and is denied
     * with the catalogue detail.
     *
     * @return void
     */
    public function testAnonymousActorIsDenied(): void
    {
        $response = $this->patchJson('/posts/' . $this->post->id, ['title' => 'Hijacked']);

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 10102);
        $response->assertJsonPath('error.detail', self::FORBIDDEN_DETAIL);
        $response->assertJsonPath('error.meta', null);

        self::assertSame('Draft', $this->post->refresh()->title);
    }

    /**
     * Test that the gate decides for the run's actor, not the authenticated
     * user.
     *
     * @return void
     */
    public function testGateDecidesForTheRunActorNotTheAuthenticatedUser(): void
    {
        $this->actingAs(User::create(['name' => 'Other', 'email' => 'other@service.test']));

        $title = (new RetitlePostService(new ArrayInput(['post' => $this->post->id, 'title' => 'Published'])))
            ->by(EloquentActor::for($this->author))
            ->runOrFail();

        self::assertSame('Published', $title);
        self::assertSame('Published', $this->post->refresh()->title);
    }

    /**
     * Test that a service dispatched by a system actor skips the gate, which
     * would deny it as a guest, at dispatch and on the worker.
     *
     * @return void
     */
    public function testSystemActorDispatchSkipsTheGate(): void
    {
        Config::set('queue.default', 'sync');

        (new RetitlePostService(new ArrayInput(['post' => $this->post->id, 'title' => 'Archived'])))
            ->by(SystemActor::named('Archiver'))
            ->dispatch();

        self::assertSame('Archived', $this->post->refresh()->title);
    }
}
