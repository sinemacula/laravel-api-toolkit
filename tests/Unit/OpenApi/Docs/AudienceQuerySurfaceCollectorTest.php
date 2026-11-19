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
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\InternalOnlyTagController;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathOrganizationController;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\ProfileResource;
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

        app(Router::class)->get('users', [PathFixtureController::class, 'index']);

        $surfaces = [
            new QuerySurfaceDescriptor(QueryableTagResource::class, []),
            new QuerySurfaceDescriptor(TagResource::class, [], [
                new QueryRelationDescriptor('organization', OrganizationResource::class),
                new QueryRelationDescriptor('users', UserResource::class),
            ]),
            new QuerySurfaceDescriptor(PostResource::class, [], [new QueryRelationDescriptor('tags', TagResource::class)]),
            new QuerySurfaceDescriptor(UserResource::class, [], [
                new QueryRelationDescriptor('notes', polymorphic: true),
                new QueryRelationDescriptor('profile', ProfileResource::class),
                new QueryRelationDescriptor('posts', PostResource::class),
                new QueryRelationDescriptor('tags', TagResource::class),
            ]),
            new QuerySurfaceDescriptor(OrganizationResource::class, []),
        ];

        self::assertSame(['public' => [$surfaces[1], $surfaces[2], $surfaces[3], $surfaces[4]]], $this->collect($surfaces));
    }

    /**
     * Test that each configured audience is told about only the surfaces its
     * own document keeps.
     *
     * @return void
     */
    public function testEachAudienceKeepsOnlyTheSurfacesItsDocumentKeeps(): void
    {
        Config::set('api-toolkit.openapi.audiences', ['public' => [], 'internal' => []]);
        Config::set('api-toolkit.resources.resource_map', [
            Organization::class => OrganizationResource::class,
            Tag::class          => QueryableTagResource::class,
        ]);

        app(Router::class)->get('organizations', [PathOrganizationController::class, 'index']);
        app(Router::class)->get('tags', [InternalOnlyTagController::class, 'index']);

        $organization = new QuerySurfaceDescriptor(OrganizationResource::class, []);
        $tag          = new QuerySurfaceDescriptor(QueryableTagResource::class, []);

        self::assertSame(['public' => [$organization], 'internal' => [$organization, $tag]], $this->collect([$organization, $tag]));
    }

    /**
     * Test that the default audience is collected when no audience is
     * configured.
     *
     * @return void
     */
    public function testCollectsTheDefaultAudienceWhenNoneIsConfigured(): void
    {
        Config::set('api-toolkit.openapi.audiences', []);
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);

        app(Router::class)->get('users', [PathFixtureController::class, 'index']);

        $surface = new QuerySurfaceDescriptor(UserResource::class, []);

        self::assertSame(['public' => [$surface]], $this->collect([$surface]));
    }

    /**
     * Collect the given surfaces for every configured audience.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>  $surfaces
     * @return array<string, array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor>>
     */
    private function collect(array $surfaces): array
    {
        $catalogue = self::createStub(MetadataCatalogue::class);

        $catalogue->method('getQuerySurfaces')->willReturn($surfaces);

        return (new AudienceQuerySurfaceCollector(app(ExportOpenApiComponents::class), $catalogue, new AudienceConfiguration))->collect();
    }
}
