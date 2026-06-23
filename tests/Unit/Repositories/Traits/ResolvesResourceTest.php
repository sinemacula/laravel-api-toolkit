<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Cache\MetadataKeyRegistry;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Repositories\Traits\ResolvesResource;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ResolvesResource trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversTrait(ResolvesResource::class)]
final class ResolvesResourceTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that usingResource sets the custom resource class.
     *
     * @return void
     */
    public function testUsingResourceSetsCustomResourceClass(): void
    {
        $consumer = $this->createConsumer();

        /** @phpstan-ignore method.notFound */
        $result = $consumer->usingResource(UserResource::class);

        static::assertSame($consumer, $result);

        $customResource = $this->getProperty($consumer, 'customResourceClass');

        static::assertSame(UserResource::class, $customResource);
    }

    /**
     * Test that resolveResource returns the custom class when set.
     *
     * @return void
     */
    public function testResolveResourceReturnsCustomClassWhenSet(): void
    {
        $consumer = $this->createConsumer();
        // @phpstan-ignore method.notFound
        $consumer->usingResource(UserResource::class);

        $result = $this->invokeMethod($consumer, 'resolveResource', new User);

        static::assertSame(UserResource::class, $result);
    }

    /**
     * Test that resolveResource returns the mapped resource from config.
     *
     * @return void
     */
    public function testResolveResourceReturnsMappedResourceFromConfig(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $consumer = $this->createConsumer();

        $result = $this->invokeMethod($consumer, 'resolveResource', new User);

        static::assertSame(UserResource::class, $result);
    }

    /**
     * Test that resolveResource returns null when no mapping exists.
     *
     * @return void
     */
    public function testResolveResourceReturnsNullWhenNoMappingExists(): void
    {
        Config::set('api-toolkit.resources.resource_map', []);

        $consumer = $this->createConsumer();

        $result = $this->invokeMethod($consumer, 'resolveResource', new User);

        static::assertNull($result);
    }

    /**
     * Test that flushResourceCache clears the memo-cached resource
     * mappings.
     *
     * @return void
     */
    public function testFlushResourceCacheClearsMemoEntries(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $consumer = $this->createConsumer();

        $this->invokeMethod($consumer, 'resolveResource', new User);

        $consumer::flushResourceCache(); // @phpstan-ignore staticMethod.notFound

        $result = Cache::memo()->get('api-toolkit:model-resources:' . User::class);

        static::assertNull($result);
    }

    /**
     * Test that flushResourceCache on an empty memo store does not
     * throw an exception.
     *
     * @return void
     */
    public function testFlushResourceCacheOnEmptyStoreIsHarmless(): void
    {
        $consumer = $this->createConsumer();

        $consumer::flushResourceCache(); // @phpstan-ignore staticMethod.notFound

        static::assertTrue(true);
    }

    /**
     * Test that resolveResource prefers the custom resource class over
     * the configured mapping.
     *
     * @return void
     */
    public function testResolveResourcePrefersCustomResourceOverMappedResource(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $consumer = $this->createConsumer();
        // @phpstan-ignore method.notFound
        $consumer->usingResource(TagResource::class);

        $result = $this->invokeMethod($consumer, 'resolveResource', new User);

        static::assertSame(TagResource::class, $result);
    }

    /**
     * Test that resource resolution caches mappings per model class
     * rather than sharing a single cache entry.
     *
     * @return void
     */
    public function testResourceResolutionIsCachedPerModelClass(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);
        Config::set('api-toolkit.resources.resource_map.' . Tag::class, TagResource::class);

        $consumer = $this->createConsumer();

        static::assertSame(UserResource::class, $this->invokeMethod($consumer, 'resolveResource', new User));
        static::assertSame(TagResource::class, $this->invokeMethod($consumer, 'resolveResource', new Tag));
    }

    /**
     * Test that the resolution methods remain accessible to consuming
     * repository subclasses as protected extension points.
     *
     * @return void
     */
    public function testResolutionMethodsAreAccessibleFromSubclasses(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        assert($this->app !== null);

        $repository = new class ($this->app) extends UserRepository {
            /**
             * Expose the protected resolveResource method.
             *
             * @param  \Illuminate\Database\Eloquent\Model  $model
             * @return string|null
             */
            public function exposeResolveResource(Model $model): ?string
            {
                return $this->resolveResource($model);
            }

            /**
             * Expose the protected getResourceFromModel method.
             *
             * @param  \Illuminate\Database\Eloquent\Model  $model
             * @return string|null
             */
            public function exposeGetResourceFromModel(Model $model): ?string
            {
                return $this->getResourceFromModel($model);
            }
        };

        static::assertSame(UserResource::class, $repository->exposeResolveResource(new User));
        static::assertSame(UserResource::class, $repository->exposeGetResourceFromModel(new User));
    }

    /**
     * Test that resolving a model resource registers the MODEL_RESOURCES key
     * in the MetadataKeyRegistry.
     *
     * @return void
     */
    public function testResolveResourceRegistersResourcesKey(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        assert($this->app !== null);

        $consumer = $this->createConsumer();

        $this->invokeMethod($consumer, 'resolveResource', new User);

        $registry    = $this->app->make(MetadataKeyRegistry::class);
        $expectedKey = CacheKeys::MODEL_RESOURCES->resolveKey([User::class]);

        static::assertContains($expectedKey, $registry->keys());
    }

    /**
     * Create a test consumer class that uses the ResolvesResource trait.
     *
     * @return object
     */
    private function createConsumer(): object
    {
        return new class {
            use ResolvesResource;
        };
    }
}
