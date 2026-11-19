<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Enums\FilterValueShape;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\OpenApi\Metadata\ApiExceptionDiscoverer;
use SineMacula\ApiToolkit\OpenApi\Metadata\ConfigMetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorCatalogueReader;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceReader;
use SineMacula\ApiToolkit\Query\PageSize;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\EqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\InOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotEqualOperator;
use Tests\Fixtures\Discovery\Primary\DiscoveredUserResource;
use Tests\Fixtures\Discovery\Primary\Nested\DiscoveredPostResource;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\CappedPageUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ConfigMetadataCatalogue adapter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ConfigMetadataCatalogue::class)]
final class ConfigMetadataCatalogueTest extends TestCase
{
    /**
     * Test that getResourceMap returns the resource map from config.
     *
     * @return void
     */
    public function testGetResourceMapReturnsConfiguredMap(): void
    {
        Config::set('api-toolkit.resources.paths', []);
        Config::set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
            Post::class => PostResource::class,
        ]);

        $catalogue = $this->makeCatalogue();

        self::assertSame([
            User::class => UserResource::class,
            Post::class => PostResource::class,
        ], $catalogue->getResourceMap());
    }

    /**
     * Test that getResourceMap is empty when nothing yields a binding.
     *
     * @return void
     */
    public function testGetResourceMapReturnsEmptyArrayWhenUnconfigured(): void
    {
        Config::set('api-toolkit.resources.paths', []);
        Config::set('api-toolkit.resources.resource_map', []);

        $catalogue = $this->makeCatalogue();

        self::assertSame([], $catalogue->getResourceMap());
    }

    /**
     * Provide resource maps the export refuses, with the binding each names.
     *
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function unexportableResourceMapProvider(): iterable
    {
        yield 'a non-Eloquent key' => [[\stdClass::class => UserResource::class], 'stdClass => ' . UserResource::class];
        yield 'a resource that is not an API resource' => [[User::class => \stdClass::class], User::class . ' => stdClass'];
    }

    /**
     * Test that the export refuses a binding runtime would refuse, rather than
     * documenting a resource no request can serve.
     *
     * @param  array<string, string>  $map
     * @param  string  $binding
     * @return void
     */
    #[DataProvider('unexportableResourceMapProvider')]
    public function testGetResourceMapRefusesABindingThatIsNotAModelAndAResource(array $map, string $binding): void
    {
        Config::set('api-toolkit.resources.paths', []);
        Config::set('api-toolkit.resources.resource_map', $map);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            'The api-toolkit.resources.resource_map setting must be a map of Eloquent model classes to API resource classes; [\'%s\'] given.',
            $binding,
        ));

        $this->makeCatalogue()->getResourceMap();
    }

    /**
     * Test that discovered resources appear even with an empty config map.
     *
     * @return void
     */
    public function testGetResourceMapIncludesDiscoveredResourcesWhenConfigEmpty(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->discoveryFixturePath()]);
        Config::set('api-toolkit.resources.resource_map', []);

        $catalogue = $this->makeCatalogue();

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $catalogue->getResourceMap());
    }

    /**
     * Test that configured entries win over and precede discovered ones.
     *
     * @return void
     */
    public function testGetResourceMapUnionsConfiguredAndDiscoveredResources(): void
    {
        Config::set('api-toolkit.resources.paths', [$this->discoveryFixturePath()]);
        Config::set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $catalogue = $this->makeCatalogue();

        self::assertSame([
            User::class => UserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $catalogue->getResourceMap());
    }

    /**
     * Test that getOperatorsFor returns the tokens the registry admits on the
     * capability.
     *
     * @return void
     */
    public function testGetOperatorsForReturnsTheTokensTheRegistryAdmits(): void
    {
        $registry = new OperatorRegistry;
        $registry->register('$eq', new EqualOperator, Capability::EXACT, Capability::RANGE);
        $registry->register('$neq', new NotEqualOperator, Capability::ENUM);
        $registry->register('$starts', static function (): void {}, Capability::RANGE);

        $catalogue = $this->makeCatalogueWithRegistry($registry);

        self::assertSame(['$eq', '$starts'], $catalogue->getOperatorsFor(Capability::RANGE));
        self::assertSame(['$eq'], $catalogue->getOperatorsFor(Capability::EXACT));
        self::assertSame([], $catalogue->getOperatorsFor(Capability::OPAQUE));
    }

    /**
     * Test that getOperatorShape returns the shape a registered handler
     * declares, and null for a handler declaring none or an unknown token.
     *
     * @return void
     */
    public function testGetOperatorShapeReturnsTheShapeTheHandlerDeclares(): void
    {
        $registry = new OperatorRegistry;
        $registry->register('$in', new InOperator, Capability::EXACT);
        $registry->register('$starts', static function (): void {}, Capability::EXACT);

        $catalogue = $this->makeCatalogueWithRegistry($registry);

        self::assertSame(FilterValueShape::LIST, $catalogue->getOperatorShape('$in'));
        self::assertNull($catalogue->getOperatorShape('$starts'));
        self::assertNull($catalogue->getOperatorShape('$eq'));
    }

    /**
     * Test that getErrorCatalogue returns one descriptor per ErrorCode case.
     *
     * @return void
     */
    public function testGetErrorCatalogueReturnsOneDescriptorPerCode(): void
    {
        $catalogue   = $this->makeCatalogue();
        $descriptors = $catalogue->getErrorCatalogue();

        self::assertCount(count(ErrorCode::cases()), $descriptors);
    }

    /**
     * Test that every item in the error catalogue is an ErrorDescriptor.
     *
     * @return void
     */
    public function testGetErrorCatalogueReturnsErrorDescriptorInstances(): void
    {
        $catalogue = $this->makeCatalogue();

        foreach ($catalogue->getErrorCatalogue() as $descriptor) {
            self::assertInstanceOf(ErrorDescriptor::class, $descriptor);
        }
    }

    /**
     * Test that getQuerySurfaces returns one surface per resource.
     *
     * @return void
     */
    public function testGetQuerySurfacesReturnsOneSurfacePerRegisteredResource(): void
    {
        Config::set('api-toolkit.resources.paths', []);
        Config::set('api-toolkit.resources.resource_map', [Post::class => PostResource::class]);

        $surfaces = $this->makeCatalogue()->getQuerySurfaces();

        self::assertCount(1, $surfaces);
        self::assertSame(PostResource::class, $surfaces[0]->resource);
        self::assertNotSame([], $surfaces[0]->columns);
    }

    /**
     * Test that getQuerySurfaces reads each column's operators from the
     * registry.
     *
     * @return void
     */
    public function testGetQuerySurfacesReadsTheOperatorsFromTheRegistry(): void
    {
        Config::set('api-toolkit.resources.paths', []);
        Config::set('api-toolkit.resources.resource_map', [Post::class => PostResource::class]);

        $registry = new OperatorRegistry;
        $registry->register('$starts', static function (): void {}, Capability::EXACT);

        $columns = $this->makeCatalogueWithRegistry($registry)->getQuerySurfaces()[0]->columns;

        self::assertSame(['title'], array_column($columns, 'column'));
        self::assertSame(['$starts'], $columns[0]->operators);
    }

    /**
     * Test that getQuerySurface reads the resource over the given model alone,
     * with the resource governing each relation, and nothing unless given an
     * API resource and a model.
     *
     * @return void
     */
    public function testGetQuerySurfaceReadsOneResourceOverTheGivenModel(): void
    {
        Config::set('api-toolkit.resources.paths', []);
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class, Post::class => PostResource::class]);

        $catalogue = $this->makeCatalogue();
        $surface   = $catalogue->getQuerySurface(UserResource::class, User::class);

        self::assertNotNull($surface);
        self::assertSame([User::class], $surface->models);
        self::assertSame(PostResource::class, array_column($surface->relations, 'resource', 'relation')['posts']);
        self::assertNull($catalogue->getQuerySurface(User::class, User::class));
        self::assertNull($catalogue->getQuerySurface(UserResource::class, UserResource::class));
        self::assertNull($catalogue->getQuerySurface(null, User::class));
        self::assertNull($catalogue->getQuerySurface(UserResource::class, null));
    }

    /**
     * Test that getQueryLimits returns every configured structural cap.
     *
     * @return void
     */
    public function testGetQueryLimitsReturnsEveryCapResolvedFromConfig(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 42);

        $limits = $this->makeCatalogue()->getQueryLimits();

        self::assertSame(
            [...QueryCostLimits::NAMES, QueryParameterValidator::MAX_LIMIT],
            array_keys($limits),
        );
        self::assertSame(42, $limits[QueryCostLimits::MAX_NODES]);
    }

    /**
     * Test that the page-size ceiling is reported with the structural caps.
     *
     * @return void
     */
    public function testGetQueryLimitsReportsThePageSizeCeiling(): void
    {
        Config::set('api-toolkit.parser.max_limit', 250);

        self::assertSame(250, $this->makeCatalogue()->getQueryLimits()[QueryParameterValidator::MAX_LIMIT]);
    }

    /**
     * Test that a page-size ceiling of digits, as the environment gives, is
     * reported as an integer.
     *
     * @return void
     */
    public function testGetQueryLimitsReadsADigitStringPageSizeCeiling(): void
    {
        Config::set('api-toolkit.parser.max_limit', '75');

        self::assertSame(75, $this->makeCatalogue()->getQueryLimits()[QueryParameterValidator::MAX_LIMIT]);
    }

    /**
     * Test that a fractional page-size ceiling fails the export rather than
     * documenting a ceiling the request path does not enforce.
     *
     * @return void
     */
    public function testGetQueryLimitsRefusesAFractionalPageSizeCeiling(): void
    {
        Config::set('api-toolkit.parser.max_limit', '75.9');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.parser.max_limit setting must be an integer of at least 0, or null; [\'75.9\'] given.');

        $this->makeCatalogue()->getQueryLimits();
    }

    /**
     * Test that a page-size ceiling configured off is reported as zero.
     *
     * @return void
     */
    public function testGetQueryLimitsReportsAnUnsetPageSizeCeilingAsZero(): void
    {
        Config::set('api-toolkit.parser.max_limit', null);

        self::assertSame(0, $this->makeCatalogue()->getQueryLimits()[QueryParameterValidator::MAX_LIMIT]);
    }

    /**
     * Test that getPageSize returns the given resource's bounds, or the
     * configured ones.
     *
     * @return void
     */
    public function testGetPageSizeReturnsTheResourceBoundsOrTheConfiguredOnes(): void
    {
        self::assertEquals(new PageSize(2, 2), $this->makeCatalogue()->getPageSize(CappedPageUserResource::class));
        self::assertEquals(new PageSize(100, 50), $this->makeCatalogue()->getPageSize(null));
    }

    /**
     * Test that a cap configured off is reported as zero.
     *
     * @return void
     */
    public function testGetQueryLimitsReportsADisabledCapAsZero(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', null);

        self::assertSame(0, $this->makeCatalogue()->getQueryLimits()[QueryCostLimits::MAX_DEPTH]);
    }

    /**
     * Test that getSearchBounds returns the three search term bounds.
     *
     * @return void
     */
    public function testGetSearchBoundsReturnsTheConfiguredBounds(): void
    {
        Config::set('api-toolkit.search.max_length', 64);
        Config::set('api-toolkit.search.max_words', 4);

        self::assertSame([
            'min_word_length' => 3,
            'max_length'      => 64,
            'max_words'       => 4,
        ], $this->makeCatalogue()->getSearchBounds());
    }

    /**
     * Test that a shortest word beneath the floor fails the export rather than
     * being reported at the floor.
     *
     * @return void
     */
    public function testGetSearchBoundsRefusesAShortestWordBeneathTheFloor(): void
    {
        Config::set('api-toolkit.search.min_word_length', 1);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.search.min_word_length setting must be an integer of at least 3; [1] given.');

        $this->makeCatalogue()->getSearchBounds();
    }

    /**
     * Build a catalogue using the container-resolved default registry.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\ConfigMetadataCatalogue
     */
    private function makeCatalogue(): ConfigMetadataCatalogue
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry $registry */
        $registry = $this->app->make(OperatorRegistry::class);

        return $this->makeCatalogueWithRegistry($registry);
    }

    /**
     * Build a catalogue with an explicit registry instance.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry  $registry
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\ConfigMetadataCatalogue
     */
    private function makeCatalogueWithRegistry(OperatorRegistry $registry): ConfigMetadataCatalogue
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery $discovery */
        $discovery = $this->app->make(ResourceDiscovery::class);

        return new ConfigMetadataCatalogue(
            $registry,
            new ErrorCatalogueReader(new ApiExceptionDiscoverer([])),
            $discovery,
            new QuerySurfaceReader(app(SchemaIntrospectionProvider::class)),
        );
    }

    /**
     * Resolve the absolute path to the primary discovery fixture directory.
     *
     * @return string
     */
    private function discoveryFixturePath(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/Discovery/Primary';
    }
}
