<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\ConfigMetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Output\FilesystemDocumentWriter;
use SineMacula\ApiToolkit\Providers\Registrars\ContainerBindingRegistrar;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use SineMacula\ApiToolkit\Search\Drivers\MySqlNgramSearchDriver;
use SineMacula\ApiToolkit\Search\Drivers\PostgresTrigramSearchDriver;
use SineMacula\ApiToolkit\Search\Drivers\SqliteSearchDriver;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Input\StorePayload;
use Tests\Fixtures\Models\Log;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\MissingColumnQueryableResource;
use Tests\Fixtures\Resources\SearchableComputedResource;
use Tests\Fixtures\Resources\SensitiveQueryableResource;
use Tests\Fixtures\Resources\TrailingIndexSortableResource;
use Tests\Fixtures\Resources\UnbackedQueryableResource;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;
use Tests\TestCase;

/**
 * Integration tests for the ContainerBindingRegistrar.
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
     * Test that all toolkit container bindings resolve after registration.
     *
     * @return void
     */
    public function testRegisterBindsAllContainerServices(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');

        /** @var string $alias */
        $alias = $config->get('api-toolkit.parser.alias');

        self::assertInstanceOf(ApiQueryParser::class, $app->make($alias));
        self::assertInstanceOf(ResourceMetadataProvider::class, $app->make(ResourceMetadataProvider::class));
        self::assertInstanceOf(SchemaIntrospectionProvider::class, $app->make(SchemaIntrospectionProvider::class));
        self::assertInstanceOf(OperatorRegistry::class, $app->make(OperatorRegistry::class));
        self::assertInstanceOf(SearchDriverRegistry::class, $app->make(SearchDriverRegistry::class));
        self::assertInstanceOf(SchemaValidator::class, $app->make(SchemaValidator::class));
        self::assertInstanceOf(WritePool::class, $app->make(WritePool::class));
        self::assertInstanceOf(CacheManager::class, $app->make(CacheManager::class));
    }

    /**
     * Test that exporter ports and lifecycle singletons bind as expected.
     *
     * @return void
     */
    public function testRegisterBindsExporterPortsAndLifecycleSingletons(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        self::assertInstanceOf(ConfigMetadataCatalogue::class, $app->make(MetadataCatalogue::class));
        self::assertInstanceOf(FilesystemDocumentWriter::class, $app->make(DocumentWriter::class));

        self::assertSame($app->make(RuntimeContext::class), $app->make(RuntimeContext::class));
        self::assertSame($app->make(MetadataGeneration::class), $app->make(MetadataGeneration::class));
        self::assertSame($app->make(MetadataCacheWriter::class), $app->make(MetadataCacheWriter::class));
        self::assertSame($app->make(ServiceRunner::class), $app->make(ServiceRunner::class));
    }

    /**
     * Test that the bound schema validator carries the shipped rule set.
     *
     * @return void
     */
    public function testRegisterBindsSchemaValidatorWithTheShippedRuleSet(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        SchemaCompiler::clearCache();

        $validator = $app->make(SchemaValidator::class);

        $this->expectException(InvalidSchemaException::class);

        $validator->validate([User::class => UnbackedQueryableResource::class]);
    }

    /**
     * Test that the shipped rules refuse a filter on a column the table lacks.
     *
     * @return void
     */
    public function testRegisterBindsSchemaValidatorRefusingAColumnTheTableDoesNotCarry(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        SchemaCompiler::clearCache();

        $validator = $app->make(SchemaValidator::class);

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage(sprintf(
            "Schema validation failed with 1 error(s):\n  - [%s] Field \"nickname\": Field is declared filterable against \"nickname\", and table \"users\" carries no such column",
            MissingColumnQueryableResource::class,
        ));

        $validator->validate([User::class => MissingColumnQueryableResource::class]);
    }

    /**
     * Test that the shipped rules refuse a filterable sensitive column.
     *
     * @return void
     */
    public function testRegisterBindsSchemaValidatorRefusingASensitiveColumnDeclaration(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        SchemaCompiler::clearCache();

        $validator = $app->make(SchemaValidator::class);

        $this->expectException(InvalidSchemaException::class);

        $validator->validate([User::class => SensitiveQueryableResource::class]);
    }

    /**
     * Test that the shipped rules refuse a sort no index leads with.
     *
     * @return void
     */
    public function testRegisterBindsSchemaValidatorRefusingASortNoIndexLeadsWith(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        SchemaCompiler::clearCache();

        $validator = $app->make(SchemaValidator::class);

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage(sprintf(
            "Schema validation failed with 1 error(s):\n  - [%s] Field \"created_at\": Field is declared sortable "
            . 'against "created_at", and no ordered index on table "logs" leads with that column',
            TrailingIndexSortableResource::class,
        ));

        $validator->validate([Log::class => TrailingIndexSortableResource::class]);
    }

    /**
     * Test that the shipped rules refuse a searchable computed field.
     *
     * @return void
     */
    public function testRegisterBindsSchemaValidatorRefusingAnUnbackedSearchDeclaration(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        SchemaCompiler::clearCache();

        $validator = $app->make(SchemaValidator::class);

        $this->expectException(InvalidSchemaException::class);

        $validator->validate([User::class => SearchableComputedResource::class]);
    }

    /**
     * Test that the search driver registry binds as a shared singleton.
     *
     * It carries a driver for each engine the package writes a search for, and
     * none for any other connection.
     *
     * @return void
     */
    public function testRegisterBindsTheSharedSearchDriverRegistryWithTheShippedDrivers(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        $registry = $app->make(SearchDriverRegistry::class);

        self::assertSame($registry, $app->make(SearchDriverRegistry::class));
        self::assertSame(['mysql', 'pgsql', 'sqlite'], $registry->connections());
        self::assertInstanceOf(MySqlNgramSearchDriver::class, $registry->resolve('mysql'));
        self::assertInstanceOf(PostgresTrigramSearchDriver::class, $registry->resolve('pgsql'));
        self::assertInstanceOf(SqliteSearchDriver::class, $registry->resolve('sqlite'));
    }

    /**
     * Test that the write pool is built with cast config and non-transactional.
     *
     * @return void
     */
    public function testRegisterBuildsWritePoolWithCastConfigAndDefaultTransactional(): void
    {
        $app = $this->getApplication();

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.deferred_writes', [
            'chunk_size' => '700',
            'pool_limit' => '900',
            'on_failure' => 'collect',
        ]);

        (new ContainerBindingRegistrar($app))->register();

        $pool = $app->make(WritePool::class);

        self::assertSame(700, $this->readProperty($pool, 'chunkSize'));
        self::assertSame(900, $this->readProperty($pool, 'poolLimit'));
        self::assertFalse($this->readProperty($pool, 'transactional'));
    }

    /**
     * Test that resolving a Payload builds a validated instance from requests.
     *
     * @return void
     */
    public function testPayloadResolutionBuildsValidatedInstanceFromRequest(): void
    {
        $app = $this->getApplication();

        $app->instance('request', Request::create('/', 'GET', [
            'title'    => 'Widget',
            'quantity' => 5,
            'status'   => 'active',
        ]));

        (new ContainerBindingRegistrar($app))->register();

        $payload = $app->make(StorePayload::class);

        self::assertInstanceOf(StorePayload::class, $payload);
        self::assertSame('Widget', $payload->title);
        self::assertSame(5, $payload->quantity);
        self::assertSame(StubStatusEnum::ACTIVE, $payload->status);
    }

    /**
     * Test that resolving a Payload throws when the request fails validation.
     *
     * @return void
     */
    public function testPayloadResolutionThrowsValidationExceptionOnInvalidRequest(): void
    {
        $app = $this->getApplication();

        $app->instance('request', Request::create('/', 'GET', []));

        (new ContainerBindingRegistrar($app))->register();

        $this->expectException(ValidationException::class);

        $app->make(StorePayload::class);
    }

    /**
     * Test that a non-Payload resolution is left untouched by the hook.
     *
     * @return void
     */
    public function testPayloadResolutionLeavesNonPayloadClassesUntouched(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        $app->make(\stdClass::class);

        self::assertFalse($app->bound(\stdClass::class));
    }

    /**
     * Test that explicit make() parameters bypass the hook.
     *
     * @return void
     */
    public function testPayloadResolutionSkipsResolutionWithExplicitParameters(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        $payload = $app->make(StorePayload::class, ['title' => 'Direct']);

        self::assertSame('Direct', $payload->title);
        self::assertNull($payload->quantity);
    }

    /**
     * Test that an already-bound Payload subclass keeps its binding.
     *
     * @return void
     */
    public function testPayloadResolutionSkipsAlreadyBoundClass(): void
    {
        $app = $this->getApplication();

        $bound = new StorePayload('Prebound');

        $app->instance(StorePayload::class, $bound);

        (new ContainerBindingRegistrar($app))->register();

        self::assertSame($bound, $app->make(StorePayload::class));
    }

    /**
     * Test that the hook skips a non-namespaced abstract before class lookup.
     *
     * @return void
     */
    public function testPayloadResolutionAddsNoClassLookupForNonNamespacedAbstract(): void
    {
        $hooked = new Container;

        (new ContainerBindingRegistrar($hooked))->register();

        self::assertSame($this->countClassLookups(new Container, 'payload_probe'), $this->countClassLookups($hooked, 'payload_probe'));
    }

    /**
     * Resolve a plain service key and count the autoloader calls made for it.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  string  $abstract
     * @return int
     */
    private function countClassLookups(Container $container, string $abstract): int
    {
        $container->bind($abstract, static fn (): string => 'resolved');

        $lookups  = 0;
        $recorder = static function (string $class) use ($abstract, &$lookups): void {
            $lookups += $class === $abstract ? 1 : 0;
        };

        spl_autoload_register($recorder);

        try {
            self::assertSame('resolved', $container->make($abstract));
        } finally {
            spl_autoload_unregister($recorder);
        }

        return $lookups;
    }

    /**
     * Read a private property value from the given object via reflection.
     *
     * @param  object  $object
     * @param  string  $property
     * @return mixed
     */
    private function readProperty(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
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
