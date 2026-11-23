<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Repositories\Concerns\ResolvesResource;
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

        self::assertSame($consumer, $result);

        $customResource = $this->getProperty($consumer, 'customResourceClass');

        self::assertSame(UserResource::class, $customResource);
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

        self::assertSame(UserResource::class, $result);
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

        self::assertSame(UserResource::class, $result);
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

        self::assertNull($result);
    }

    /**
     * Test that resolveResource prefers the custom resource over the mapping.
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

        self::assertSame(TagResource::class, $result);
    }

    /**
     * Test that resource resolution caches mappings per model class.
     *
     * @return void
     */
    public function testResourceResolutionIsCachedPerModelClass(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);
        Config::set('api-toolkit.resources.resource_map.' . Tag::class, TagResource::class);

        $consumer = $this->createConsumer();

        self::assertSame(UserResource::class, $this->invokeMethod($consumer, 'resolveResource', new User));
        self::assertSame(TagResource::class, $this->invokeMethod($consumer, 'resolveResource', new Tag));
    }

    /**
     * Test that the resolution methods stay protected extension points.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
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

        self::assertSame(UserResource::class, $repository->exposeResolveResource(new User));
        self::assertSame(UserResource::class, $repository->exposeGetResourceFromModel(new User));
    }

    /**
     * Test that the resource is read from the configured map, never the cache
     * store, so a changed map is followed at once.
     *
     * @return void
     */
    public function testGetResourceFromModelFollowsTheConfiguredMapWithoutTheCacheStore(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        Cache::shouldReceive('memo')->never();

        $consumer = $this->createConsumer();

        self::assertSame(UserResource::class, $this->invokeMethod($consumer, 'getResourceFromModel', new User));

        Config::set('api-toolkit.resources.resource_map.' . User::class, TagResource::class);

        self::assertSame(TagResource::class, $this->invokeMethod($consumer, 'getResourceFromModel', new User));
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
