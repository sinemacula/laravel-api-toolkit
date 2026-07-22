<?php

declare(strict_types = 1);

namespace Tests\Feature;

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
 * A field guard that reads the row being rendered must be evaluated once per
 * element as the collection is mapped, so a single JSON body can reveal the
 * guarded field on some rows and omit it on others. This drives a two-row
 * collection (one active, one inactive) through a real route and asserts the
 * guarded email surfaces on the active row and is absent on the inactive one.
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

        // Fetch fresh instances per request so no model is flagged as recently
        // created, which would alter the response status.
        Route::get('/per-item-guarded', static function (): ApiResourceCollection {
            $users = User::query()->get();

            return new ApiResourceCollection($users, PerItemGuardedUserResource::class);
        });
    }

    /**
     * Test that the guarded field appears on the active row and is absent on
     * the inactive row within the same response body.
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
