<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Docs\AudienceQuerySurfaceCollector;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\QueryableTagResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the collector of the query surfaces each audience is told about.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(AudienceQuerySurfaceCollector::class)]
final class AudienceQuerySurfaceCollectorTest extends TestCase
{
    /**
     * Test that an audience is told about the surfaces whose schema its
     * document keeps and every surface their filters descend into, however
     * deep, and about no other.
     *
     * @return void
     */
    public function testKeepsTheDocumentedSurfacesAndEverySurfaceTheyReach(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);

        $router = $this->app?->make(Router::class);

        assert($router instanceof Router);

        $router->get('users', [PathFixtureController::class, 'index']);

        $surfaces = [
            new QuerySurfaceDescriptor(QueryableTagResource::class, []),
            new QuerySurfaceDescriptor(TagResource::class, [], [new QueryRelationDescriptor('users', UserResource::class)]),
            new QuerySurfaceDescriptor(PostResource::class, [], [new QueryRelationDescriptor('tags', TagResource::class)]),
            new QuerySurfaceDescriptor(UserResource::class, [], [
                new QueryRelationDescriptor('notes', polymorphic: true),
                new QueryRelationDescriptor('organization', OrganizationResource::class),
                new QueryRelationDescriptor('posts', PostResource::class),
            ]),
        ];

        $catalogue = self::createStub(MetadataCatalogue::class);

        $catalogue->method('getQuerySurfaces')->willReturn($surfaces);

        $exporter = $this->app?->make(ExportOpenApiComponents::class);

        assert($exporter instanceof ExportOpenApiComponents);

        $collected = (new AudienceQuerySurfaceCollector($exporter, $catalogue, new AudienceConfiguration))->collect();

        self::assertSame(['public'], array_keys($collected));
        self::assertSame([$surfaces[1], $surfaces[2], $surfaces[3]], $collected['public']);
    }
}
