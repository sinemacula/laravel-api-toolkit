<?php

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\ConfigMetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Output\FilesystemDocumentWriter;
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
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\LikeOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotEqualOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NotNullOperator;
use SineMacula\ApiToolkit\Repositories\Criteria\Operators\NullOperator;
use SineMacula\ApiToolkit\RouteLinting\Configuration\ConfigRuleConfiguration;
use SineMacula\ApiToolkit\RouteLinting\Contracts\Inflector;
use SineMacula\ApiToolkit\RouteLinting\Contracts\RouteSource;
use SineMacula\ApiToolkit\RouteLinting\Contracts\RuleConfiguration;
use SineMacula\ApiToolkit\RouteLinting\Inflection\FrameworkInflector;
use SineMacula\ApiToolkit\RouteLinting\RouteLintEngine;
use SineMacula\ApiToolkit\RouteLinting\Rules\ApiResourceAlignmentRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\KebabCaseRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\LowercaseRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\NestingDepthRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\PluralCollectionsRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\RouteNameRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\SlashSanityRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\StandardMethodsRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\Support\SegmentNormaliser;
use SineMacula\ApiToolkit\RouteLinting\Rules\Support\VerbDenylist;
use SineMacula\ApiToolkit\RouteLinting\Rules\VerbInPathRule;
use SineMacula\ApiToolkit\RouteLinting\Sources\RouterRouteSource;
use SineMacula\ApiToolkit\Services\SchemaIntrospector;
use SineMacula\ApiToolkit\Services\SchemaValidator;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateAccessors;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateComputedFields;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateConstraints;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateGuards;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateRelationClasses;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateRelationInterfaces;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateRelationMethods;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateTransformers;

/**
 * Registers the toolkit container bindings.
 *
 * Binds the query parser, resource metadata provider, schema introspector,
 * operator registry, schema validator, write pool, and cache manager to the
 * service container.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ContainerBindingRegistrar
{
    /**
     * Create a new container binding registrar instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function __construct(

        /** The service container to register the bindings on. */
        private readonly Container $container,

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
        $this->registerSchemaValidator();
        $this->registerWritePool();
        $this->registerCacheManager();
        $this->registerOpenApiExporter();
        $this->registerRouteLinter();
    }

    /**
     * Bind the API query parser to the service container.
     *
     * @return void
     */
    private function registerQueryParser(): void
    {
        $this->container->scoped(Config::get('api-toolkit.parser.alias'), fn ($app) => new ApiQueryParser);
    }

    /**
     * Bind the ResourceMetadataProvider to the service container.
     *
     * @return void
     */
    private function registerResourceMetadataProvider(): void
    {
        $this->container->singleton(
            \SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider::class,
            \SineMacula\ApiToolkit\Http\Resources\ResourceMetadataService::class,
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
            $registry->register('$like', new LikeOperator);
            $registry->register('$in', new InOperator);
            $registry->register('$between', new BetweenOperator);
            $registry->register('$contains', new ContainsOperator);
            $registry->register('$null', new NullOperator);
            $registry->register('$notNull', new NotNullOperator);

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
        $this->container->singleton(SchemaValidator::class, fn (): SchemaValidator => new SchemaValidator(
            new ValidateGuards,
            new ValidateTransformers,
            new ValidateRelationClasses,
            new ValidateRelationInterfaces,
            new ValidateRelationMethods,
            new ValidateComputedFields,
            new ValidateConstraints,
            new ValidateAccessors,
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

            $chunk_size    = Config::get('api-toolkit.deferred_writes.chunk_size', 500);
            $pool_limit    = Config::get('api-toolkit.deferred_writes.pool_limit', 10000);
            $on_failure    = Config::get('api-toolkit.deferred_writes.on_failure', 'collect');
            $transactional = Config::get('api-toolkit.deferred_writes.transactional', false);

            return new WritePool(
                is_numeric($chunk_size) ? (int) $chunk_size : 500,
                is_numeric($pool_limit) ? (int) $pool_limit : 10000,
                FlushStrategy::from(is_string($on_failure) ? $on_failure : 'collect'),
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
     * Bind the OpenAPI exporter ports to their default adapters.
     *
     * The metadata catalogue and document-writer ports bind to their
     * filesystem/config adapters; the use case, builders, and assembler are
     * auto-resolved through constructor injection from these bindings.
     *
     * @return void
     */
    private function registerOpenApiExporter(): void
    {
        $this->container->singleton(MetadataCatalogue::class, ConfigMetadataCatalogue::class);
        $this->container->singleton(DocumentWriter::class, FilesystemDocumentWriter::class);
    }

    /**
     * Bind the route-linter ports to their default adapters.
     *
     * Binds the four ports as singletons and assembles the RouteLintEngine with
     * the rule set in a fixed declared order (R1, R2, R3, R4, R5, R7, R8, R9,
     * R11). The LintRoutes use case is auto-resolved through constructor
     * injection from these bindings; no explicit binding for it is required.
     *
     * @return void
     */
    private function registerRouteLinter(): void
    {
        $this->container->singleton(RouteSource::class, fn ($app) => new RouterRouteSource($app['router']));

        $this->container->singleton(RuleConfiguration::class, ConfigRuleConfiguration::class);

        $this->container->singleton(Inflector::class, function (): FrameworkInflector {

            $uncountables = Config::get('api-toolkit.route_linting.uncountables');

            return new FrameworkInflector(is_array($uncountables) ? $uncountables : []);
        });

        $this->container->singleton(RouteLintEngine::class, function (): RouteLintEngine {

            $inflector = $this->container->make(Inflector::class);

            $denylist = Config::get('api-toolkit.route_linting.verb_denylist');
            $hints    = Config::get('api-toolkit.route_linting.remediation_hints');

            return new RouteLintEngine(
                new VerbInPathRule(
                    new SegmentNormaliser($inflector),
                    new VerbDenylist(
                        is_array($denylist) ? $denylist : [],
                        is_array($hints) ? $hints : [],
                    ),
                ),
                new KebabCaseRule,
                new LowercaseRule,
                new PluralCollectionsRule($inflector),
                new SlashSanityRule,
                new StandardMethodsRule,
                new RouteNameRule,
                new ApiResourceAlignmentRule,
                new NestingDepthRule,
            );
        });
    }
}
