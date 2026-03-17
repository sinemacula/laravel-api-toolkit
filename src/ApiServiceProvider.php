<?php

namespace SineMacula\ApiToolkit;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Log\LogManager;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Console\ValidateSchemasCommand;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequestsWithRedis;
use SineMacula\ApiToolkit\Listeners\NotificationListener;
use SineMacula\ApiToolkit\Listeners\OctaneFlushListener;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Logging\CloudWatchLogger;
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
use SineMacula\Http\Enums\HttpHeader;
use SineMacula\Http\Enums\MediaType;

/**
 * API service provider.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class ApiServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadTranslationFiles();
        $this->offerPublishing();
        $this->registerMorphMap();
        $this->validateSchemas();
        $this->registerTrashedMacros();
        $this->registerExportMacros();
        $this->registerStreamMacros();
        $this->registerMiddleware();
        $this->registerCloudwatchLogger();
        $this->registerNotificationLogging();
        $this->registerWritePoolFlushSubscriber();
        $this->registerOctaneFlushListener();
        $this->registerQueueFlushSubscriber();
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/api-toolkit.php', 'api-toolkit',
        );

        $this->replaceConfigRecursivelyFrom(
            __DIR__ . '/../config/logging.php', 'logging',
        );

        $this->registerQueryParser();
        $this->registerResourceMetadataProvider();
        $this->registerSchemaIntrospector();
        $this->registerOperatorRegistry();
        $this->registerSchemaValidator();
        $this->registerWritePool();
        $this->registerCacheManager();

        $this->commands([
            ValidateSchemasCommand::class,
        ]);
    }

    /**
     * Load the package translation files.
     *
     * @return void
     */
    private function loadTranslationFiles(): void
    {
        $this->loadTranslationsFrom(
            __DIR__ . '/../resources/lang', 'api-toolkit',
        );

        $this->publishes([
            __DIR__ . '/../resources/lang' => resource_path('lang/vendor/api-toolkit'),
        ], 'translations');
    }

    /**
     * Publish any package specific configuration and assets.
     *
     * @return void
     */
    private function offerPublishing(): void
    {
        if (!$this->app->runningInConsole()) {
            return; // @codeCoverageIgnore
        }

        if (!function_exists('config_path')) {
            return; // @codeCoverageIgnore
        }

        $this->publishes([
            __DIR__ . '/../config/api-toolkit.php' => config_path('api-toolkit.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/../stubs/logs-table.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_logs_table.php'),
        ], 'migrations');
    }

    /**
     * Register the morph map dynamically based on the configuration.
     *
     * @return void
     */
    private function registerMorphMap(): void
    {
        $map = Config::get('api-toolkit.resources.resource_map', []);

        if (!Config::get('api-toolkit.resources.enable_dynamic_morph_mapping') || !is_array($map)) {
            return;
        }

        $map = collect($map)
            ->mapWithKeys(function ($resource, $model) {

                if (method_exists($resource, 'getResourceType')) {
                    return [$resource::getResourceType() => $model];
                }

                return [];
            })
            ->all();

        Relation::enforceMorphMap($map);
    }

    /**
     * Register the trashed macros to the Request facade.
     *
     * @return void
     */
    private function registerTrashedMacros(): void
    {
        Request::macro('includeTrashed', fn () => $this->get('include_trashed', false) === 'true');
        Request::macro('onlyTrashed', fn () => $this->get('only_trashed', false) === 'true');
    }

    /**
     * Register the export macros to the Request facade.
     *
     * @return void
     */
    private function registerExportMacros(): void
    {
        Request::macro('expectsExport', fn () => config('api-toolkit.exports.enabled') && ($this->expectsCsv() || $this->expectsXml()));

        Request::macro('expectsCsv', fn () => strtolower($this->header(HttpHeader::ACCEPT->getName())) === MediaType::TEXT_CSV->getMimeType()
            && in_array('csv', config('api-toolkit.exports.supported_formats', []), true));

        Request::macro('expectsXml', fn () => strtolower($this->header(HttpHeader::ACCEPT->getName())) === MediaType::APPLICATION_XML->getMimeType()
            && in_array('xml', config('api-toolkit.exports.supported_formats', []), true));

        Request::macro('expectsPdf', fn () => strtolower($this->header(HttpHeader::ACCEPT->getName())) === MediaType::APPLICATION_PDF->getMimeType());
    }

    /**
     * Register the stream macros to the Request facade.
     *
     * @return void
     */
    private function registerStreamMacros(): void
    {
        Request::macro('expectsStream', fn () => strtolower($this->header(HttpHeader::ACCEPT->getName())) === MediaType::TEXT_EVENT_STREAM->getMimeType());
    }

    /**
     * Register any relevant middleware.
     *
     * @return void
     */
    private function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);

        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        if (Config::get('api-toolkit.parser.register_middleware', true)) {
            $kernel->pushMiddleware(ParseApiQuery::class);
        }

        $this->registerMaintenanceModeMiddleware($kernel);
        $this->registerJsonPrettyPrintMiddleware($kernel);
        $this->registerThrottleMiddleware($router);
    }

    /**
     * Register the maintenance mode middleware swap.
     *
     * When enabled, the toolkit's PreventRequestsDuringMaintenance middleware
     * is prepended to the global middleware stack. Since it extends Laravel's
     * built-in middleware, it takes precedence without requiring removal of
     * the original.
     *
     * @param  \Illuminate\Foundation\Http\Kernel  $kernel
     * @return void
     */
    private function registerMaintenanceModeMiddleware(HttpKernel $kernel): void
    {
        if (!Config::get('api-toolkit.middleware.maintenance_mode_swap.enabled', true)) {
            return;
        }

        $kernel->prependMiddleware(PreventRequestsDuringMaintenance::class);
    }

    /**
     * Register the JsonPrettyPrint middleware.
     *
     * When scope is 'global', the middleware is pushed to the global stack.
     * When scope is 'api', it is appended to the 'api' middleware group.
     *
     * @param  \Illuminate\Foundation\Http\Kernel  $kernel
     * @return void
     */
    private function registerJsonPrettyPrintMiddleware(HttpKernel $kernel): void
    {
        if (!Config::get('api-toolkit.middleware.json_pretty_print.enabled', true)) {
            return;
        }

        $scope = Config::get('api-toolkit.middleware.json_pretty_print.scope', 'global');

        if ($scope === 'api') {
            $kernel->appendMiddlewareToGroup('api', JsonPrettyPrint::class);

            return;
        }

        $kernel->pushMiddleware(JsonPrettyPrint::class);
    }

    /**
     * Register the throttle middleware alias.
     *
     * When a custom class is provided via config, that class is used.
     * Otherwise, the toolkit auto-detects between standard and Redis
     * variants based on the configured cache driver.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    private function registerThrottleMiddleware(Router $router): void
    {
        if (!Config::get('api-toolkit.middleware.throttle.enabled', true)) {
            return;
        }

        $router->aliasMiddleware('throttle', $this->getThrottleMiddleware());
    }

    /**
     * Return the throttle middleware that should be used.
     *
     * @return class-string
     */
    private function getThrottleMiddleware(): string
    {
        /** @var class-string|null $custom_class */
        $custom_class = Config::get('api-toolkit.middleware.throttle.class');

        if ($custom_class !== null) {
            return $custom_class;
        }

        return Config::get('cache.default') !== 'redis'
            ? ThrottleRequests::class
            : ThrottleRequestsWithRedis::class;
    }

    /**
     * Register the Cloudwatch logger driver.
     *
     * @return void
     */
    private function registerCloudwatchLogger(): void
    {
        if (!class_exists(\Aws\CloudWatchLogs\CloudWatchLogsClient::class)) {
            return;
        }

        $this->app->make(LogManager::class)->extend('cloudwatch', fn ($app, array $config) => (new CloudWatchLogger)->__invoke($config));
    }

    /**
     * Register the notification logging functionality.
     *
     * @return void
     */
    private function registerNotificationLogging(): void
    {
        if (!Config::get('api-toolkit.notifications.enable_logging', true)) {
            return;
        }

        Event::listen(NotificationSending::class, [NotificationListener::class, 'sending']);
        Event::listen(NotificationSent::class, [NotificationListener::class, 'sent']);
    }

    /**
     * Bind the API query parser to the service container.
     *
     * @return void
     */
    private function registerQueryParser(): void
    {
        $this->app->scoped(Config::get('api-toolkit.parser.alias'), fn ($app) => new ApiQueryParser);
    }

    /**
     * Bind the ResourceMetadataProvider to the service container.
     *
     * @return void
     */
    private function registerResourceMetadataProvider(): void
    {
        $this->app->singleton(
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
        $this->app->singleton(
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
        $this->app->singleton(OperatorRegistry::class, function (): OperatorRegistry {

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
        $this->app->singleton(SchemaValidator::class, fn (): SchemaValidator => new SchemaValidator(
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
     * Validate all registered resource schemas.
     *
     * @return void
     */
    private function validateSchemas(): void
    {
        if (Config::get('api-toolkit.resources.validate_schemas', false) !== true) {
            return;
        }

        $resourceMap = Config::get('api-toolkit.resources.resource_map', []);

        if (!is_array($resourceMap) || $resourceMap === []) {
            return;
        }

        $this->app->make(SchemaValidator::class)->validate($resourceMap);
    }

    /**
     * Bind the WritePool to the service container as a scoped singleton.
     *
     * @return void
     */
    private function registerWritePool(): void
    {
        $this->app->scoped(WritePool::class, fn (): WritePool => new WritePool(
            (int) Config::get('api-toolkit.deferred_writes.chunk_size', 500),
            (int) Config::get('api-toolkit.deferred_writes.pool_limit', 10000),
            FlushStrategy::from((string) Config::get('api-toolkit.deferred_writes.on_failure', 'log')),
        ));
    }

    /**
     * Bind the CacheManager to the service container.
     *
     * @return void
     */
    private function registerCacheManager(): void
    {
        $this->app->singleton(CacheManager::class);
    }

    /**
     * Subscribe the write pool flush subscriber to lifecycle events.
     *
     * @return void
     */
    private function registerWritePoolFlushSubscriber(): void
    {
        Event::subscribe(WritePoolFlushSubscriber::class);
    }

    /**
     * Register the Octane flush listener if configured and Octane is
     * installed.
     *
     * @return void
     */
    private function registerOctaneFlushListener(): void
    {
        if (!(bool) Config::get('api-toolkit.lifecycle.octane')) {
            return;
        }

        if (!class_exists(\Laravel\Octane\Events\OperationTerminated::class)) {
            return;
        }

        Event::listen(\Laravel\Octane\Events\OperationTerminated::class, OctaneFlushListener::class);
    }

    /**
     * Register the queue flush subscriber if configured.
     *
     * @return void
     */
    private function registerQueueFlushSubscriber(): void
    {
        if (!(bool) Config::get('api-toolkit.lifecycle.queue')) {
            return;
        }

        Event::subscribe(QueueFlushSubscriber::class);
    }
}
