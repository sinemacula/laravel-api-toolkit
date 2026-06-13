<?php

namespace Tests\Unit\OpenApi\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\OpenApi\Metadata\ConfigMetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorCatalogueReader;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\EqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotEqualOperator;
use Tests\Fixtures\Models\User;
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
class ConfigMetadataCatalogueTest extends TestCase
{
    /**
     * Test that getResourceMap returns the resource map from config.
     *
     * @return void
     */
    public function testGetResourceMapReturnsConfiguredMap(): void
    {
        $this->app['config']->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $catalogue = $this->makeCatalogue();

        static::assertSame([User::class => UserResource::class], $catalogue->getResourceMap());
    }

    /**
     * Test that getResourceMap returns an empty array when config is absent.
     *
     * @return void
     */
    public function testGetResourceMapReturnsEmptyArrayWhenUnconfigured(): void
    {
        $this->app['config']->set('api-toolkit.resources.resource_map', null);

        $catalogue = $this->makeCatalogue();

        static::assertSame([], $catalogue->getResourceMap());
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

        static::assertSame(['$eq', '$neq'], $catalogue->getOperatorTokens());
    }

    /**
     * Test that getOperatorTokens covers all twelve default registered tokens.
     *
     * @return void
     */
    public function testGetOperatorTokensCoversAllDefaultTokens(): void
    {
        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry $registry */
        $registry  = $this->app->make(OperatorRegistry::class);
        $catalogue = $this->makeCatalogueWithRegistry($registry);

        $tokens = $catalogue->getOperatorTokens();

        static::assertContains('$eq', $tokens);
        static::assertContains('$neq', $tokens);
        static::assertContains('$gt', $tokens);
        static::assertContains('$lt', $tokens);
        static::assertContains('$ge', $tokens);
        static::assertContains('$le', $tokens);
        static::assertContains('$like', $tokens);
        static::assertContains('$in', $tokens);
        static::assertContains('$between', $tokens);
        static::assertContains('$contains', $tokens);
        static::assertContains('$null', $tokens);
        static::assertContains('$notNull', $tokens);
        static::assertCount(12, $tokens);
    }

    /**
     * Test that getStructuralOperators returns the four fixed structural tokens.
     *
     * @return void
     */
    public function testGetStructuralOperatorsReturnsAllFour(): void
    {
        $catalogue  = $this->makeCatalogue();
        $structural = $catalogue->getStructuralOperators();

        static::assertCount(4, $structural);
        static::assertContains('$and', $structural);
        static::assertContains('$or', $structural);
        static::assertContains('$has', $structural);
        static::assertContains('$hasnt', $structural);
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

        static::assertCount(count(ErrorCode::cases()), $descriptors);
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
            static::assertInstanceOf(ErrorDescriptor::class, $descriptor);
        }
    }

    /**
     * Build a catalogue using the container-resolved default registry.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\ConfigMetadataCatalogue
     */
    private function makeCatalogue(): ConfigMetadataCatalogue
    {
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
        return new ConfigMetadataCatalogue($registry, new ErrorCatalogueReader);
    }
}
