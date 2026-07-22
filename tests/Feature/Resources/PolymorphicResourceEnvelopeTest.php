<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\PolymorphicResource;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests for a single polymorphic resource through the HTTP kernel.
 *
 * A single mapped model is returned from a real route as a PolymorphicResource
 * and asserted to render the data-wrapped envelope with its type discriminator
 * - the JsonResource wrap that unit tests never travel through, which only a
 * real dispatch confirms. A field excluded via withoutFields drops from the
 * body even when it is a default field.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PolymorphicResource::class)]
final class PolymorphicResourceEnvelopeTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a single mapped polymorphic resource route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);

        // Fetch a fresh instance per request so the model is not flagged as
        // recently created, which would alter the response status.
        Route::get('/polymorphic-item', static fn (): PolymorphicResource => (new PolymorphicResource(User::query()->firstOrFail()))->withoutFields(['email']));
    }

    /**
     * Test that the single polymorphic resource renders a data-wrapped typed
     * item with the excluded field absent.
     *
     * @return void
     */
    public function testSinglePolymorphicResourceRendersTypedDataWrappedItem(): void
    {
        $response = $this->getJson('/polymorphic-item');

        $response->assertOk();
        $response->assertJsonPath('data._type', 'users');
        $response->assertJsonPath('data.name', 'Alice');

        self::assertArrayNotHasKey('email', (array) $response->json('data'));
    }
}
