<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Exceptions\ExceptionMap;
use SineMacula\ApiToolkit\Exceptions\GoneException;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Exceptions\NotFoundException;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\ConfigMetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Output\FilesystemDocumentWriter;
use SineMacula\ApiToolkit\OpenApi\Schema\DefinedSchemaRegistry;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\Providers\Registrars\ContainerBindingRegistrar;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use SineMacula\ApiToolkit\Search\Drivers\MySqlNgramSearchDriver;
use SineMacula\ApiToolkit\Search\Drivers\PostgresTrigramSearchDriver;
use SineMacula\ApiToolkit\Search\Drivers\SqliteSearchDriver;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use SineMacula\ApiToolkit\Services\ServiceRunner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Fixtures\Input\StorePayload;
use Tests\Fixtures\Models\Gathering;
use Tests\Fixtures\Models\Log;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\GatheringResource;
use Tests\Fixtures\Resources\GatheringSessionResource;
use Tests\Fixtures\Resources\MissingColumnQueryableResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\SearchableComputedResource;
use Tests\Fixtures\Resources\SensitiveQueryableResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\TrailingIndexSortableResource;
use Tests\Fixtures\Resources\UnbackedQueryableResource;
use Tests\Fixtures\Resources\UnboundScopeResource;
use Tests\Fixtures\Resources\UserResource;
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
        self::assertSame($app->make(EnumSchemaRegistry::class), $app->make(EnumSchemaRegistry::class));
        self::assertSame($app->make(DefinedSchemaRegistry::class), $app->make(DefinedSchemaRegistry::class));
    }

    /**
     * Test that the exception map is built from the config on every resolution.
     *
     * @return void
     */
    public function testRegisterBindsTheExceptionMapToTheCurrentConfig(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        $default = $app->make(ExceptionMap::class);

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');
        $config->set('api-toolkit.exceptions.map', [NotFoundHttpException::class => GoneException::class]);

        $configured = $app->make(ExceptionMap::class);

        self::assertNotSame($default, $configured);
        self::assertSame(NotFoundException::class, $default->targetFor(NotFoundHttpException::class));
        self::assertSame(GoneException::class, $configured->targetFor(NotFoundHttpException::class));
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
     * Test that the shipped rules accept scopes bound to their model and refuse
     * one the model does not define.
     *
     * @return void
     */
    public function testRegisterBindsSchemaValidatorRefusingAScopeTheModelDoesNotDefine(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        SchemaCompiler::clearCache();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [GatheringResource::class, GatheringSessionResource::class]);

        $validator = $app->make(SchemaValidator::class);

        $validator->validate([Gathering::class => GatheringResource::class]);

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage(sprintf(
            "Schema validation failed with 1 error(s):\n  - [%s] Field \"__scope__:cancelled\": Scope \"cancelled\" is not a local scope of model \"%s\"",
            UnboundScopeResource::class,
            Gathering::class,
        ));

        $validator->validate([Gathering::class => UnboundScopeResource::class]);
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
     * Test that the shipped rules refuse a limit on a to-one relation.
     *
     * @return void
     */
    public function testRegisterBindsSchemaValidatorRefusingALimitOnAToOneRelation(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        SchemaCompiler::clearCache();

        $resource = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'limited_to_one_users';

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('organization', OrganizationResource::class)->limit(2),
                );
            }
        };

        Config::set(ResourceDiscovery::RESOURCES_KEY, [OrganizationResource::class]);

        $validator = $app->make(SchemaValidator::class);

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage(sprintf(
            "Schema validation failed with 1 error(s):\n  - [%s] Field \"organization\": Relation \"organization\" declares a limit but its method returns a to-one relation",
            $resource::class,
        ));

        $validator->validate([User::class => $resource::class]);
    }

    /**
     * Test that the shipped rules refuse a relation child whose type cannot be
     * selected.
     *
     * @return void
     */
    public function testRegisterBindsSchemaValidatorRefusingAnUncataloguedRelationChild(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        SchemaCompiler::clearCache();

        $validator = $app->make(SchemaValidator::class);

        $this->expectException(InvalidSchemaException::class);
        $this->expectExceptionMessage(sprintf(
            "Schema validation failed with 1 error(s):\n  - [%s] Field \"tags\": Relation \"tags\" renders %s, "
            . 'which is neither mapped nor under resources.paths, so its type cannot be selected',
            PostResource::class,
            TagResource::class,
        ));

        $map = [
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
            User::class         => UserResource::class,
        ];

        Config::set('api-toolkit.resources.resource_map', $map);

        $validator->validate($map);
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
     * Test that the shipped operators admit, per capability, exactly its
     * defaults in their documented order.
     *
     * @return void
     */
    public function testRegisterSeedsTheOperatorRegistryWithEachCapabilitysDefaults(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        $registry = $app->make(OperatorRegistry::class);

        foreach (Capability::cases() as $capability) {
            self::assertSame($capability->defaultOperators(), $registry->tokensFor($capability), $capability->value);
        }

        self::assertSame(['$eq', '$in', '$neq', '$gt', '$ge', '$lt', '$le', '$between', '$contains', '$null', '$notNull'], $registry->tokens());
    }

    /**
     * Test that the write pool is built from environment spellings of its
     * settings.
     *
     * @return void
     */
    public function testRegisterBuildsWritePoolFromEnvironmentSpellings(): void
    {
        $app = $this->getApplication();

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.deferred_writes.chunk_size', '700');
        $config->set('api-toolkit.deferred_writes.pool_limit', '900');
        $config->set('api-toolkit.deferred_writes.transactional', 'off');

        (new ContainerBindingRegistrar($app))->register();

        $pool = $app->make(WritePool::class);

        self::assertSame(700, $this->readProperty($pool, 'chunkSize'));
        self::assertSame(900, $this->readProperty($pool, 'poolLimit'));
        self::assertFalse($this->readProperty($pool, 'transactional'));
        self::assertSame($app->make('db.transactions'), $this->resolveTracker($pool));
    }

    /**
     * Test that the write pool resolves the transaction tracker bound when it
     * checks, not the one bound when it was built.
     *
     * @return void
     */
    public function testRegisterBuildsWritePoolResolvingTheTrackerBoundAtEachCheck(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        $pool = $app->make(WritePool::class);

        $app->instance('db.transactions', $rebound = new DatabaseTransactionsManager);

        self::assertSame($rebound, $this->resolveTracker($pool));
    }

    /**
     * Test that the write pool resolves no transaction tracker when the
     * container binds none, or binds something else.
     *
     * @return void
     */
    public function testRegisterBuildsWritePoolWithoutATrackerWhenNoneIsBound(): void
    {
        $app = $this->getApplication();

        (new ContainerBindingRegistrar($app))->register();

        $pool = $app->make(WritePool::class);

        $app->instance('db.transactions', new \stdClass);

        self::assertNull($this->resolveTracker($pool));

        $app->offsetUnset('db.transactions');

        self::assertFalse($app->bound('db.transactions'));
        self::assertNull($this->resolveTracker($pool));
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
     * Resolve the transaction tracker the write pool would check against.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Concerns\WritePool  $pool
     * @return mixed
     */
    private function resolveTracker(WritePool $pool): mixed
    {
        $resolver = $this->readProperty($pool, 'transactions');

        assert($resolver instanceof \Closure);

        return $resolver();
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
