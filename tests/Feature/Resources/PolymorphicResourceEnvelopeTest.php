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

        // A recently created model would alter the response status
        Route::get('/polymorphic-item', static fn (): PolymorphicResource => (new PolymorphicResource(User::query()->firstOrFail()))->withoutFields(['email']));
    }

    /**
     * Test that a single polymorphic resource renders a data-wrapped typed item
     * without the excluded field.
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
