<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Http\Resources\PolymorphicResource;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use Tests\Fixtures\Discovery\Primary\DiscoveredUserResource;
use Tests\Fixtures\Discovery\Primary\Nested\DiscoveredPostResource;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Integration tests for attribute-based resource discovery at boot.
 *
 * Boots the service provider against the discovery fixtures and asserts the
 * discovered bindings merge beneath the configured resource map (an explicit
 * entry always wins), feed the dynamic morph map, and resolve end-to-end
 * through polymorphic serialization.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(ResourceDiscovery::class)]
final class ResourceDiscoveryIntegrationTest extends TestCase
{
    /**
     * Test that discovered bindings merge beneath the configured resource map:
     * an explicit entry wins for its model while discovered bindings fill the
     * rest.
     *
     * @return void
     */
    public function testDiscoveredBindingsMergeBeneathConfiguredMap(): void
    {
        $this->configureDiscovery(map: [User::class => UserResource::class]);

        $this->bootProvider();

        $map = config('api-toolkit.resources.resource_map');

        self::assertIsArray($map);
        self::assertSame(UserResource::class, $map[User::class]);
        self::assertSame(DiscoveredPostResource::class, $map[Post::class]);
    }

    /**
     * Test that with no configured map at all, discovery alone populates the
     * resource map - a consumer can omit the config entirely.
     *
     * @return void
     */
    public function testDiscoveryAlonePopulatesTheResourceMap(): void
    {
        $this->configureDiscovery();

        $this->bootProvider();

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], config('api-toolkit.resources.resource_map'));
    }

    /**
     * Test that discovered bindings feed the dynamic morph map.
     *
     * @return void
     */
    public function testDiscoveredBindingsFeedTheMorphMap(): void
    {
        $this->configureDiscovery();

        $this->bootProvider();

        $morphMap = Relation::morphMap();

        self::assertSame(User::class, $morphMap['discovered_users']);
        self::assertSame(Post::class, $morphMap['discovered_posts']);
    }

    /**
     * Test that a discovered binding resolves end-to-end through polymorphic
     * serialization without any resource_map entry.
     *
     * @return void
     */
    public function testDiscoveredBindingResolvesPolymorphically(): void
    {
        $this->configureDiscovery();

        $this->bootProvider();

        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);

        $data = (new PolymorphicResource($user))->toArray(Request::create('/test'));

        self::assertIsArray($data);
        self::assertSame('discovered_users', $data['_type']);
    }

    /**
     * Test that boot leaves the resource map untouched when nothing is
     * discovered.
     *
     * @return void
     */
    public function testBootLeavesMapUntouchedWhenNothingIsDiscovered(): void
    {
        $this->configureDiscovery(paths: [], map: [User::class => UserResource::class]);

        $this->bootProvider();

        self::assertSame([
            User::class => UserResource::class,
        ], config('api-toolkit.resources.resource_map'));
    }

    /**
     * Configure the discovery paths and resource map for the test.
     *
     * @param  array<int, string>|null  $paths
     * @param  array<class-string, class-string>  $map
     * @return void
     */
    private function configureDiscovery(?array $paths = null, array $map = []): void
    {
        config()->set('api-toolkit.resources.paths', $paths ?? [dirname(__DIR__) . '/Fixtures/Discovery/Primary']);
        config()->set('api-toolkit.resources.resource_map', $map);
        config()->set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
    }

    /**
     * Re-boot the service provider so discovery runs against the configured
     * environment.
     *
     * @return void
     */
    private function bootProvider(): void
    {
        assert($this->app !== null);

        (new ApiServiceProvider($this->app))->boot();
    }
}
