<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PerItemGuardedUserResource;
use Tests\TestCase;

/**
 * Feature tests for a per-row field guard varying visibility inside one body.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
#[CoversClass(ApiResourceCollection::class)]
final class PerItemGuardResponseTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a two-row per-item-guarded collection route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'inactive']);

        // A recently created model would alter the response status
        Route::get('/per-item-guarded', static function (): ApiResourceCollection {
            $users = User::query()->get();

            return new ApiResourceCollection($users, PerItemGuardedUserResource::class);
        });
    }

    /**
     * Test that the guarded field appears on the active row and not on the
     * inactive row.
     *
     * @return void
     */
    public function testGuardVariesVisibilityPerRowInOneBody(): void
    {
        $response = $this->getJson('/per-item-guarded');

        $response->assertOk();

        $response->assertJsonPath('data.0.name', 'Alice');
        $response->assertJsonPath('data.0.email', 'alice@example.com');

        $response->assertJsonPath('data.1.name', 'Bob');

        self::assertArrayHasKey('email', (array) $response->json('data.0'));
        self::assertArrayNotHasKey('email', (array) $response->json('data.1'));
    }
}
