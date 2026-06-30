<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources;

use Illuminate\Foundation\Application;
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
final class ResourceMetadataServiceTest extends TestCase
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

        self::assertSame('users', $result);
    }

    /**
     * Test that resolveFields delegates to the resource class static method.
     *
     * @return void
     */
    public function testResolveFieldsDelegatesToResourceClass(): void
    {
        $result = $this->service->resolveFields(UserResource::class);

        self::assertSame(UserResource::resolveFields(), $result);
    }

    /**
     * Test that getAllFields delegates to the resource class static method.
     *
     * @return void
     */
    public function testGetAllFieldsDelegatesToResourceClass(): void
    {
        $result = $this->service->getAllFields(UserResource::class);

        self::assertSame(UserResource::getAllFields(), $result);
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

        self::assertSame(UserResource::eagerLoadMapFor($fields), $result);
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

        self::assertSame(UserResource::eagerLoadCountsFor($aliases), $result);
    }

    /**
     * Test that eagerLoadSumsFor delegates to the resource class static method.
     *
     * @return void
     */
    public function testEagerLoadSumsForDelegatesToResourceClass(): void
    {
        $requested = ['posts' => ['id']];

        $result = $this->service->eagerLoadSumsFor(UserResource::class, $requested);

        self::assertSame(UserResource::eagerLoadSumsFor($requested), $result);
    }

    /**
     * Test that eagerLoadAveragesFor delegates to the resource class static
     * method.
     *
     * @return void
     */
    public function testEagerLoadAveragesForDelegatesToResourceClass(): void
    {
        $requested = ['posts' => ['id']];

        $result = $this->service->eagerLoadAveragesFor(UserResource::class, $requested);

        self::assertSame(UserResource::eagerLoadAveragesFor($requested), $result);
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

        self::assertInstanceOf(ResourceMetadataService::class, $resolved);
        self::assertSame($resolved, $app->make(ResourceMetadataProvider::class));
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function getApplication(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }
}
