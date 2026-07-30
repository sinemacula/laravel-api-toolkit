<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\GuardedRelationUserResource;
use Tests\Fixtures\Resources\NestedChildGuardPostResource;
use Tests\TestCase;

/**
 * Feature tests for relation-level guards and nested child field guards in a
 * real JSON response body.
 *
 * A guarded relation is security-adjacent: a consumer needs proof the embedded
 * relation is absent from the actual body, not merely from a unit resolve.
 * These tests drive a resource whose relation carries a request-scoped guard,
 * and a parent whose embedded child declares its own field guard, through real
 * responses to prove the guarded relation and the child's guarded field drop
 * out unless the request permits them.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
#[CoversClass(ValueResolver::class)]
final class RelationGuardResponseTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with guarded-relation and nested-child-guard routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        $organization = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp']);
        $alice        = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active', 'organization_id' => $organization->id]);

        Post::create(['user_id' => $alice->id, 'title' => 'First Post', 'body' => 'Content', 'published' => true]);

        // Fetch fresh instances per request so no model is flagged as recently
        // created, which would alter the response status.
        Route::get('/guarded-relation', static fn (): GuardedRelationUserResource => new GuardedRelationUserResource(
            User::query()->with('organization')->firstOrFail(),
        ));

        Route::get('/nested-child-guard', static fn (): NestedChildGuardPostResource => new NestedChildGuardPostResource(
            Post::query()->with('user')->firstOrFail(),
        ));
    }

    /**
     * Test that a guarded relation is absent from the body when the guard
     * fails.
     *
     * @return void
     */
    public function testGuardedRelationIsAbsentWhenGuardFails(): void
    {
        $response = $this->getJson('/guarded-relation');

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Alice');

        self::assertArrayNotHasKey('organization', (array) $response->json('data'));
    }

    /**
     * Test that a guarded relation is embedded when the guard passes.
     *
     * @return void
     */
    public function testGuardedRelationIsEmbeddedWhenGuardPasses(): void
    {
        $response = $this->getJson('/guarded-relation?include_org=yes');

        $response->assertOk();
        $response->assertJsonPath('data.organization.name', 'Acme Corp');
        $response->assertJsonPath('data.organization.slug', 'acme-corp');
    }

    /**
     * Test that an embedded child's guarded field is hidden while its
     * transformer still applies when the child's guard fails.
     *
     * @return void
     */
    public function testEmbeddedChildGuardedFieldIsHiddenWhenChildGuardFails(): void
    {
        $response = $this->getJson('/nested-child-guard');

        $response->assertOk();
        $response->assertJsonPath('data.title', 'First Post');
        $response->assertJsonPath('data.user.name', 'ALICE');

        self::assertArrayNotHasKey('email', (array) $response->json('data.user'));
    }

    /**
     * Test that an embedded child's guarded field is revealed when the child's
     * guard passes on the same request.
     *
     * @return void
     */
    public function testEmbeddedChildGuardedFieldIsRevealedWhenChildGuardPasses(): void
    {
        $response = $this->getJson('/nested-child-guard?show=yes');

        $response->assertOk();
        $response->assertJsonPath('data.user.email', 'alice@example.com');
    }
}
