<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Providers\Registrars\ContainerBindingRegistrar;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Services\SchemaValidator;
use Tests\TestCase;

/**
 * Integration tests for the ContainerBindingRegistrar.
 *
 * The binding behaviour is pinned by the ApiServiceProvider integration suite;
 * this test proves the registrar binds its surface when invoked directly.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ContainerBindingRegistrar::class)]
final class ContainerBindingRegistrarTest extends TestCase
{
    /**
     * Test that all toolkit container bindings resolve after the registrar is
     * invoked.
     *
     * @return void
     */
    public function testRegisterBindsAllContainerServices(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app->make('config');

        /** @var string $alias */
        $alias = $config->get('api-toolkit.parser.alias');

        self::assertInstanceOf(ApiQueryParser::class, $app->make($alias));
        self::assertInstanceOf(ResourceMetadataProvider::class, $app->make(ResourceMetadataProvider::class));
        self::assertInstanceOf(SchemaIntrospectionProvider::class, $app->make(SchemaIntrospectionProvider::class));
        self::assertInstanceOf(OperatorRegistry::class, $app->make(OperatorRegistry::class));
        self::assertInstanceOf(SchemaValidator::class, $app->make(SchemaValidator::class));
        self::assertInstanceOf(WritePool::class, $app->make(WritePool::class));
        self::assertInstanceOf(CacheManager::class, $app->make(CacheManager::class));
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
