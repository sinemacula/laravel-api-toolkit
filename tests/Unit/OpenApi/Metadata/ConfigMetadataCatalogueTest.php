<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\OpenApi\Metadata\ApiExceptionDiscoverer;
use SineMacula\ApiToolkit\OpenApi\Metadata\ConfigMetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorCatalogueReader;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceReader;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\EqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotEqualOperator;
use Tests\Fixtures\Discovery\Primary\DiscoveredUserResource;
use Tests\Fixtures\Discovery\Primary\Nested\DiscoveredPostResource;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
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
     * Test that getResourceMap returns an empty array when neither the config
     * map nor discovery yields any binding.
     *
     * @return void
     */
    public function testGetResourceMapReturnsEmptyArrayWhenUnconfigured(): void
    {
        Config::set('api-toolkit.resources.paths', []);
        Config::set('api-toolkit.resources.resource_map', null);

        $catalogue = $this->makeCatalogue();

        self::assertSame([], $catalogue->getResourceMap());
    }

    /**
     * Test that discovered resources absent from the config map appear in the
     * returned map, even when the config map is empty.
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
     * Test that discovered resources are unioned with the configured map:
     * configured entries keep their order and win on a model collision, while
     * discovered bindings for models absent from the config are appended.
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
     * Test that getOperatorTokens returns the tokens registered in the bound
     * OperatorRegistry.
     *
     * @return void
     */
    public function testGetOperatorTokensReturnsRegistryTokens(): void
    {
        $registry = new OperatorRegistry;
        $registry->register('$eq', new EqualOperator);
        $registry->register('$neq', new NotEqualOperator);

        $catalogue = $this->makeCatalogueWithRegistry($registry);

        self::assertSame(['$eq', '$neq'], $catalogue->getOperatorTokens());
    }

    /**
     * Test that getOperatorTokens covers all eleven default registered tokens,
     * and that the deleted partial-match token is not among them.
     *
     * @return void
     */
    public function testGetOperatorTokensCoversAllDefaultTokens(): void
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry $registry */
        $registry  = $this->app->make(OperatorRegistry::class);
        $catalogue = $this->makeCatalogueWithRegistry($registry);

        $tokens = $catalogue->getOperatorTokens();

        self::assertContains('$eq', $tokens);
        self::assertContains('$neq', $tokens);
        self::assertContains('$gt', $tokens);
        self::assertContains('$lt', $tokens);
        self::assertContains('$ge', $tokens);
        self::assertContains('$le', $tokens);
        self::assertNotContains('$like', $tokens);
        self::assertContains('$in', $tokens);
        self::assertContains('$between', $tokens);
        self::assertContains('$contains', $tokens);
        self::assertContains('$null', $tokens);
        self::assertContains('$notNull', $tokens);
        self::assertCount(11, $tokens);
    }

    /**
     * Test that getStructuralOperators returns the four fixed structural
     * tokens.
     *
     * @return void
     */
    public function testGetStructuralOperatorsReturnsAllFour(): void
    {
        $catalogue  = $this->makeCatalogue();
        $structural = $catalogue->getStructuralOperators();

        self::assertCount(4, $structural);
        self::assertContains('$and', $structural);
        self::assertContains('$or', $structural);
        self::assertContains('$has', $structural);
        self::assertContains('$hasnt', $structural);
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
     * Test that getQuerySurfaces returns one surface per registered resource,
     * carrying the columns that resource declares queryable.
     *
     * @return void
     */
    public function testGetQuerySurfacesReturnsOneSurfacePerRegisteredResource(): void
    {
        Config::set('api-toolkit.resources.paths', []);
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class]);

        $surfaces = $this->makeCatalogue()->getQuerySurfaces();

        self::assertCount(1, $surfaces);
        self::assertSame(UserResource::class, $surfaces[0]->resource);
        self::assertNotSame([], $surfaces[0]->columns);
    }

    /**
     * Test that getQueryLimits returns every structural cap the query cost
     * limits declare, resolved to its configured value.
     *
     * @return void
     */
    public function testGetQueryLimitsReturnsEveryCapResolvedFromConfig(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 42);

        $limits = $this->makeCatalogue()->getQueryLimits();

        self::assertSame(array_keys(QueryCostLimits::DEFAULTS), array_keys($limits));
        self::assertSame(42, $limits[QueryCostLimits::MAX_NODES]);
    }

    /**
     * Test that a cap configured off is reported as zero, matching the way the
     * limits themselves treat a disabled dimension.
     *
     * @return void
     */
    public function testGetQueryLimitsReportsADisabledCapAsZero(): void
    {
        Config::set('api-toolkit.query_cost.max_depth', null);

        self::assertSame(0, $this->makeCatalogue()->getQueryLimits()[QueryCostLimits::MAX_DEPTH]);
    }

    /**
     * Test that getSearchBounds returns the three bounds a search term is held
     * to, read from the term itself.
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
     * Test that the shortest word is reported at the floor it is held to rather
     * than at a configured value beneath it, which the term itself refuses.
     *
     * @return void
     */
    public function testGetSearchBoundsReportsTheShortestWordFloorRatherThanAValueBeneathIt(): void
    {
        Config::set('api-toolkit.search.min_word_length', 1);

        self::assertSame(3, $this->makeCatalogue()->getSearchBounds()['min_word_length']);
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
            new QuerySurfaceReader,
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
