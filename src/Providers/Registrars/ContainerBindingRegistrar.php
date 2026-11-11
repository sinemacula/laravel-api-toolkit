<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Http\Resources\ResourceMetadataService;
use SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Contracts\ModuleResolver;
use SineMacula\ApiToolkit\OpenApi\Docs\NamespaceModuleResolver;
use SineMacula\ApiToolkit\OpenApi\Metadata\ApiExceptionDiscoverer;
use SineMacula\ApiToolkit\OpenApi\Metadata\ConfigMetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\Psr4RootMap;
use SineMacula\ApiToolkit\OpenApi\Output\FilesystemDocumentWriter;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\BetweenOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\ContainsOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\EqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\GreaterThanOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\GreaterThanOrEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\InOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LessThanOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LessThanOrEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotNullOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NullOperator;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateAccessors;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateComputedFields;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateIndexBacking;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateQueryableFields;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateRelationClasses;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateRelationInterfaces;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateRelationMethods;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateSearchableFields;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateSearchIndexes;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateSensitiveColumns;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use SineMacula\ApiToolkit\Search\Drivers\MySqlNgramSearchDriver;
use SineMacula\ApiToolkit\Search\Drivers\PostgresTrigramSearchDriver;
use SineMacula\ApiToolkit\Search\Drivers\SqliteSearchDriver;
use SineMacula\ApiToolkit\Search\SearchDriverRegistry;
use SineMacula\ApiToolkit\Services\Input\Payload;
use SineMacula\ApiToolkit\Services\ServiceRunner;

/**
 * Registers the toolkit container bindings.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ContainerBindingRegistrar
{
    /**
     * Create a new container binding registrar instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function __construct(

        /** The service container to register the bindings on */
        private Container $container,
    ) {}

    /**
     * Register the toolkit container bindings.
     *
     * @return void
     */
    public function register(): void
    {
        $this->registerQueryParser();
        $this->registerResourceMetadataProvider();
        $this->registerSchemaIntrospector();
        $this->registerOperatorRegistry();
        $this->registerSearchDriverRegistry();
        $this->registerSchemaValidator();
        $this->registerWritePool();
        $this->registerCacheManager();
        $this->registerLifecycleRuntime();
        $this->registerOpenApiExporter();
        $this->registerServiceRunner();
        $this->registerPayloadResolution();
    }

    /**
     * Bind the API query parser to the service container.
     *
     * @return void
     */
    private function registerQueryParser(): void
    {
        $alias = Config::get('api-toolkit.parser.alias');

        $this->container->scoped(is_string($alias) ? $alias : 'api.query', fn ($app) => new ApiQueryParser);
    }

    /**
     * Bind the ResourceMetadataProvider to the service container.
     *
     * @return void
     */
    private function registerResourceMetadataProvider(): void
    {
        $this->container->singleton(
            ResourceMetadataProvider::class,
            ResourceMetadataService::class,
        );
    }

    /**
     * Bind the SchemaIntrospectionProvider to the service container.
     *
     * @return void
     */
    private function registerSchemaIntrospector(): void
    {
        $this->container->singleton(
            SchemaIntrospectionProvider::class,
            SchemaIntrospector::class,
        );
    }

    /**
     * Bind the OperatorRegistry to the service container.
     *
     * @return void
     */
    private function registerOperatorRegistry(): void
    {
        $this->container->singleton(OperatorRegistry::class, function (): OperatorRegistry {

            $registry = new OperatorRegistry;

            $registry->register('$eq', new EqualOperator);
            $registry->register('$neq', new NotEqualOperator);
            $registry->register('$gt', new GreaterThanOperator);
            $registry->register('$lt', new LessThanOperator);
            $registry->register('$ge', new GreaterThanOrEqualOperator);
            $registry->register('$le', new LessThanOrEqualOperator);
            $registry->register('$in', new InOperator);
            $registry->register('$between', new BetweenOperator);
            $registry->register('$contains', new ContainsOperator);
            $registry->register('$null', new NullOperator);
            $registry->register('$notNull', new NotNullOperator);

            return $registry;
        });
    }

    /**
     * Bind the SearchDriverRegistry to the service container.
     *
     * Drivers are keyed by connection name; an unregistered connection throws
     * when searched.
     *
     * @return void
     */
    private function registerSearchDriverRegistry(): void
    {
        $this->container->singleton(SearchDriverRegistry::class, function (): SearchDriverRegistry {

            $registry    = new SearchDriverRegistry;
            $eligibility = $this->container->make(IndexEligibilityInspector::class);

            $registry->register('mysql', new MySqlNgramSearchDriver($eligibility));
            $registry->register('pgsql', new PostgresTrigramSearchDriver($eligibility));
            $registry->register('sqlite', new SqliteSearchDriver);

            return $registry;
        });
    }

    /**
     * Bind the SchemaValidator to the service container.
     *
     * @return void
     */
    private function registerSchemaValidator(): void
    {
        $this->container->singleton(SchemaValidator::class, fn (Container $app): SchemaValidator => new SchemaValidator(
            new ValidateRelationClasses,
            new ValidateRelationInterfaces,
            new ValidateRelationMethods,
            new ValidateComputedFields,
            new ValidateAccessors,
            new ValidateQueryableFields($app->make(SchemaIntrospectionProvider::class)),
            new ValidateIndexBacking($app->make(SchemaIntrospectionProvider::class), $app->make(IndexEligibilityInspector::class)),
            new ValidateSearchableFields,
            new ValidateSearchIndexes($app->make(SearchDriverRegistry::class), $app->make(SchemaIntrospectionProvider::class)),
            new ValidateSensitiveColumns,
        ));
    }

    /**
     * Bind the WritePool to the service container as a scoped singleton.
     *
     * @return void
     */
    private function registerWritePool(): void
    {
        $this->container->scoped(WritePool::class, function (): WritePool {

            $chunkSize     = Config::get('api-toolkit.deferred_writes.chunk_size', 500);
            $poolLimit     = Config::get('api-toolkit.deferred_writes.pool_limit', 10000);
            $onFailure     = Config::get('api-toolkit.deferred_writes.on_failure', 'collect');
            $transactional = Config::get('api-toolkit.deferred_writes.transactional', false);

            return new WritePool(
                is_numeric($chunkSize) ? (int) $chunkSize : 500,
                is_numeric($poolLimit) ? (int) $poolLimit : 10000,
                FlushStrategy::from(is_string($onFailure) ? $onFailure : 'collect'),
                (bool) $transactional,
            );
        });
    }

    /**
     * Bind the CacheManager to the service container.
     *
     * @return void
     */
    private function registerCacheManager(): void
    {
        $this->container->singleton(CacheManager::class);
    }

    /**
     * Bind the lifecycle runtime collaborators to the service container.
     *
     * Singletons, so write-time and flush-time share one instance per worker.
     *
     * @return void
     */
    private function registerLifecycleRuntime(): void
    {
        $this->container->singleton(RuntimeContext::class);
        $this->container->singleton(MetadataGeneration::class);
        $this->container->singleton(MetadataCacheWriter::class);
    }

    /**
     * Bind the OpenAPI exporter ports to their default adapters.
     *
     * The enum schema registry is a singleton so every resolver shares one
     * collected set per document.
     *
     * @return void
     */
    private function registerOpenApiExporter(): void
    {
        $this->container->singleton(MetadataCatalogue::class, ConfigMetadataCatalogue::class);
        $this->container->singleton(DocumentWriter::class, FilesystemDocumentWriter::class);
        $this->container->singleton(ApiExceptionDiscoverer::class, static fn (): ApiExceptionDiscoverer => ApiExceptionDiscoverer::fromComposer());
        $this->container->singleton(ModuleResolver::class, static fn (): NamespaceModuleResolver => new NamespaceModuleResolver(Psr4RootMap::fromComposer()));
        $this->container->singleton(EnumSchemaRegistry::class);
    }

    /**
     * Bind the ServiceRunner to the service container as a singleton.
     *
     * @return void
     */
    private function registerServiceRunner(): void
    {
        $this->container->singleton(ServiceRunner::class);
    }

    /**
     * Enable container resolution of concrete Payload subclasses.
     *
     * A type-hinted Payload is hydrated from the current request, like a
     * FormRequest. Explicit parameters or an existing binding bypass the hook.
     *
     * @return void
     */
    private function registerPayloadResolution(): void
    {
        $this->container->beforeResolving(static function (callable|string $abstract, array $parameters, Container $app): void {

            if (!is_string($abstract) || !str_contains($abstract, '\\') || $parameters !== []) {
                return;
            }

            if (!is_subclass_of($abstract, Payload::class) || $app->bound($abstract)) {
                return;
            }

            $app->bind($abstract, static fn (Container $app): Payload => $abstract::from($app->make(Request::class)));
        });
    }
}
