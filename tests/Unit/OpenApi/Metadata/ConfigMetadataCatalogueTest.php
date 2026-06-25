<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Metadata;

use Illuminate\Support\Facades\Config;
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
final class ConfigMetadataCatalogueTest extends TestCase
{
    /**
     * Test that getResourceMap returns the resource map from config.
     *
     * @return void
     */
    public function testGetResourceMapReturnsConfiguredMap(): void
    {
        Config::set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $catalogue = $this->makeCatalogue();

        self::assertSame([User::class => UserResource::class], $catalogue->getResourceMap());
    }

    /**
     * Test that getResourceMap returns an empty array when config is absent.
     *
     * @return void
     */
    public function testGetResourceMapReturnsEmptyArrayWhenUnconfigured(): void
    {
        Config::set('api-toolkit.resources.resource_map', null);

        $catalogue = $this->makeCatalogue();

        self::assertSame([], $catalogue->getResourceMap());
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
     * Test that getOperatorTokens covers all twelve default registered tokens.
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
        self::assertContains('$like', $tokens);
        self::assertContains('$in', $tokens);
        self::assertContains('$between', $tokens);
        self::assertContains('$contains', $tokens);
        self::assertContains('$null', $tokens);
        self::assertContains('$notNull', $tokens);
        self::assertCount(12, $tokens);
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
        return new ConfigMetadataCatalogue($registry, new ErrorCatalogueReader);
    }
}
