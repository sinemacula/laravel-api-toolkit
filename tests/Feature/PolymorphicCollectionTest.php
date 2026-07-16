<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Http\Resources\PolymorphicResource;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests for polymorphic collection serialization through the HTTP
 * kernel.
 *
 * A heterogeneous collection of mixed models is returned from a real route and
 * asserted to carry the correct per-type discriminator and field set for each
 * element - the mixed-type feed shape that unit tests only exercise one item at
 * a time. An unmapped model renders an error rather than a partial payload.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PolymorphicResource::class)]
#[CoversClass(ApiExceptionHandler::class)]
final class PolymorphicCollectionTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a mapped heterogeneous feed route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('app.debug', false);
        Config::set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
        ]);

        $organization = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp']);
        $user         = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active', 'organization_id' => $organization->id]);
        $post         = Post::create(['user_id' => $user->id, 'title' => 'Hello World', 'body' => 'Content', 'published' => true]);

        Route::get('/api/feed', static fn () => PolymorphicResource::collection(collect([$user, $organization, $post])));
    }

    /**
     * Test that each element carries its own discriminator and field set.
     *
     * @return void
     */
    public function testHeterogeneousCollectionCarriesPerTypeDiscriminators(): void
    {
        $response = $this->getJson('/api/feed');

        $response->assertOk();
        $response->assertJsonPath('data.0._type', 'users');
        $response->assertJsonPath('data.1._type', 'organizations');
        $response->assertJsonPath('data.2._type', 'posts');
        $response->assertJsonPath('data.0.name', 'Alice');
        $response->assertJsonPath('data.1.name', 'Acme Corp');
        $response->assertJsonPath('data.2.title', 'Hello World');
    }

    /**
     * Test that a model absent from the map renders an error rather than a
     * partial payload.
     *
     * @return void
     */
    public function testUnmappedModelRendersAnError(): void
    {
        Config::set('api-toolkit.resources.resource_map', []);

        $response = $this->getJson('/api/feed');

        $response->assertStatus(500);
        $response->assertJsonPath('error.status', 500);
    }
}
