<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Http\Resources\ResourceTypeIndex;
use Tests\Fixtures\Discovery\Primary\ChildDiscoveredUserResource;
use Tests\Fixtures\Discovery\Primary\DiscoveredUserResource;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the index of selectable resources by type.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceTypeIndex::class)]
final class ResourceTypeIndexTest extends TestCase
{
    /**
     * Test that a resource map value is indexed under its type.
     *
     * @return void
     */
    public function testMappedResourceIsIndexedUnderItsType(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class, Post::class => PostResource::class]);

        self::assertSame([UserResource::class], ResourceTypeIndex::classesFor('users'));
        self::assertSame([PostResource::class], ResourceTypeIndex::classesFor('posts'));
    }

    /**
     * Test that a discovered resource no model binds is indexed.
     *
     * @return void
     */
    public function testUnboundDiscoveredResourceIsIndexed(): void
    {
        Config::set(ResourceDiscovery::RESOURCES_KEY, [PostResource::class]);

        self::assertSame([PostResource::class], ResourceTypeIndex::classesFor('posts'));
    }

    /**
     * Test that every resource of a shared type is listed once, mapped first.
     *
     * @return void
     */
    public function testSharedTypeListsEveryResourceOnce(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => DiscoveredUserResource::class]);
        Config::set(ResourceDiscovery::RESOURCES_KEY, [ChildDiscoveredUserResource::class, DiscoveredUserResource::class]);

        self::assertSame([DiscoveredUserResource::class, ChildDiscoveredUserResource::class], ResourceTypeIndex::classesFor('discovered_users'));
        self::assertSame([DiscoveredUserResource::class, ChildDiscoveredUserResource::class], ResourceTypeIndex::all());
    }

    /**
     * Test that a type no catalogued resource carries has no classes.
     *
     * @return void
     */
    public function testUnknownTypeHasNoClasses(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);

        self::assertSame([], ResourceTypeIndex::classesFor('user'));
        self::assertSame([], ResourceTypeIndex::classesFor('Users'));
    }

    /**
     * Test that entries which are not API resources, and unreadable lists, are
     * left out.
     *
     * @return void
     */
    public function testEntriesThatAreNotResourcesAreLeftOut(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => \stdClass::class, Post::class => 42]);
        Config::set(ResourceDiscovery::RESOURCES_KEY, 'not-a-list');

        self::assertSame([], ResourceTypeIndex::all());

        Config::set('api-toolkit.resources.resource_map', 'not-a-map');
        Config::set(ResourceDiscovery::RESOURCES_KEY, [PostResource::class, 'Missing\Resource']);

        self::assertSame([PostResource::class], ResourceTypeIndex::all());
    }

    /**
     * Test that the index follows a change to either list it is built from.
     *
     * @return void
     */
    public function testIndexIsRebuiltWhenEitherListChanges(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);

        self::assertSame([UserResource::class], ResourceTypeIndex::all());

        Config::set(ResourceDiscovery::RESOURCES_KEY, [PostResource::class]);

        self::assertSame([UserResource::class, PostResource::class], ResourceTypeIndex::all());

        Config::set('api-toolkit.resources.resource_map', []);

        self::assertSame([], ResourceTypeIndex::classesFor('users'));
        self::assertSame([PostResource::class], ResourceTypeIndex::classesFor('posts'));
    }
}
