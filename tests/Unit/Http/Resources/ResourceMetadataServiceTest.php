<?php

namespace Tests\Unit\Http\Resources;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Http\Resources\ResourceMetadataService;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ResourceMetadataService.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceMetadataService::class)]
class ResourceMetadataServiceTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Http\Resources\ResourceMetadataService */
    private ResourceMetadataService $service;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ResourceMetadataService;
    }

    /**
     * Test that getResourceType delegates to the resource class static method.
     *
     * @return void
     */
    public function testGetResourceTypeDelegatesToResourceClass(): void
    {
        $result = $this->service->getResourceType(UserResource::class);

        static::assertSame('users', $result);
    }

    /**
     * Test that resolveFields delegates to the resource class static method.
     *
     * @return void
     */
    public function testResolveFieldsDelegatesToResourceClass(): void
    {
        $result = $this->service->resolveFields(UserResource::class);

        static::assertSame(UserResource::resolveFields(), $result);
    }

    /**
     * Test that getAllFields delegates to the resource class static method.
     *
     * @return void
     */
    public function testGetAllFieldsDelegatesToResourceClass(): void
    {
        $result = $this->service->getAllFields(UserResource::class);

        static::assertSame(UserResource::getAllFields(), $result);
    }

    /**
     * Test that eagerLoadMapFor delegates to the resource class static method.
     *
     * @return void
     */
    public function testEagerLoadMapForDelegatesToResourceClass(): void
    {
        $fields = ['id', 'name', 'organization'];

        $result = $this->service->eagerLoadMapFor(UserResource::class, $fields);

        static::assertSame(UserResource::eagerLoadMapFor($fields), $result);
    }

    /**
     * Test that eagerLoadCountsFor delegates to the resource class static
     * method.
     *
     * @return void
     */
    public function testEagerLoadCountsForDelegatesToResourceClass(): void
    {
        $aliases = ['posts'];

        $result = $this->service->eagerLoadCountsFor(UserResource::class, $aliases);

        static::assertSame(UserResource::eagerLoadCountsFor($aliases), $result);
    }

    /**
     * Test that the service provider registers the ResourceMetadataProvider
     * binding as a singleton resolving to ResourceMetadataService.
     *
     * @return void
     */
    public function testServiceProviderRegistersResourceMetadataProviderBinding(): void
    {
        $app = $this->getApplication();

        $resolved = $app->make(ResourceMetadataProvider::class);

        static::assertInstanceOf(ResourceMetadataService::class, $resolved);
        static::assertSame($resolved, $app->make(ResourceMetadataProvider::class));
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function getApplication(): \Illuminate\Foundation\Application
    {
        assert($this->app !== null);

        return $this->app;
    }
}
