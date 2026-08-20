<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
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
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Tests\Fixtures\Input\StorePayload;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\SensitiveQueryableResource;
use Tests\Fixtures\Resources\UnbackedQueryableResource;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;
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
     * Test that the OpenAPI exporter ports bind to their default adapters and
     * the lifecycle collaborators bind as shared singletons.
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
        self::assertSame($app->make(MetadataCacheWriter::class), $app->make(MetadataCacheWriter::class));
        self::assertSame($app->make(ServiceRunner::class), $app->make(ServiceRunner::class));
    }

    /**
     * Test that the bound schema validator carries the shipped rule set rather
     * than resolving as an empty validator that accepts every schema.
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
     * Test that the shipped rule set refuses a resource declaring a sensitive
     * column filterable, so the defence-in-depth denylist is enforced at boot.
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
     * Test that the write pool is built with the configured values cast to int
     * and the transactional flag defaulting to false when unconfigured.
     *
     * @return void
     */
    public function testRegisterBuildsWritePoolWithCastConfigAndDefaultTransactional(): void
    {
        $app = $this->getApplication();

        /** @var \Illuminate\Contracts\Config\Repository $config */
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
     * Test that resolving a Payload subclass by name yields a validated,
     * hydrated instance built from the current request.
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
     * Test that resolving a Payload subclass throws a validation exception when
     * the current request fails the payload's rules.
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
     * Test that a non-Payload resolution is left untouched by the hook, so the
     * hook binds nothing for classes outside the Payload hierarchy.
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
     * Test that explicit make() parameters bypass the hook, so the class builds
     * directly from the given values without request validation.
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
     * Test that an already-bound Payload subclass is not hijacked, so a
     * pre-registered binding wins over the request-building hook.
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
