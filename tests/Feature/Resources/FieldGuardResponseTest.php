<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\AuthUserGuardedUserResource;
use Tests\Fixtures\Resources\GuardedUserResource;
use Tests\TestCase;

/**
 * Feature tests for field guards and transformers in a real JSON body.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
final class FieldGuardResponseTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a guarded resource route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);

        // A recently created model would make the resource response a 201
        Route::get('/guarded', static fn (): GuardedUserResource => new GuardedUserResource(User::query()->firstOrFail()));

        Route::get('/auth-guarded', static fn (): AuthUserGuardedUserResource => new AuthUserGuardedUserResource(User::query()->firstOrFail()));
    }

    /**
     * Test that the guard hides the field and the transformer applies when the
     * guard parameter is absent.
     *
     * @return void
     */
    public function testGuardHidesFieldAndTransformerAppliesInResponse(): void
    {
        $response = $this->getJson('/guarded');

        $response->assertOk();
        $response->assertJsonPath('data.name', 'ALICE');

        self::assertArrayNotHasKey('email', (array) $response->json('data'));
    }

    /**
     * Test that the guarded field is present when the guard permits it.
     *
     * @return void
     */
    public function testGuardRevealsFieldWhenPermitted(): void
    {
        $response = $this->getJson('/guarded?show=yes');

        $response->assertOk();
        $response->assertJsonPath('data.email', 'alice@example.com');
    }

    /**
     * Test that a guard keyed on the authenticated user hides the field for a
     * guest.
     *
     * @return void
     */
    public function testAuthUserGuardHidesFieldForGuest(): void
    {
        $response = $this->getJson('/auth-guarded');

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Alice');

        self::assertArrayNotHasKey('email', (array) $response->json('data'));
    }

    /**
     * Test that a guard keyed on the authenticated user reveals the field to an
     * admin.
     *
     * @return void
     */
    public function testAuthUserGuardRevealsFieldForActingAdmin(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'status' => 'active']);

        $response = $this->actingAs($admin)->getJson('/auth-guarded');

        $response->assertOk();
        $response->assertJsonPath('data.email', 'alice@example.com');
    }
}
