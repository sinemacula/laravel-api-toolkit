<?php

namespace SineMacula\ApiToolkit;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as LaravelPreventRequestsDuringMaintenance;
use Illuminate\Log\LogManager;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequestsWithRedis;
use SineMacula\ApiToolkit\Listeners\NotificationListener;
use SineMacula\ApiToolkit\Logging\CloudWatchLogger;
use SineMacula\ApiToolkit\Support\Discovery\RepositoryMapAutoDiscoverer;
use SineMacula\ApiToolkit\Support\Discovery\ResourceMapAutoDiscoverer;

/**
 * API service provider.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
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
        $this->registerTrashedMacros();
        $this->registerExportMacros();
        $this->registerStreamMacros();
        $this->registerMiddleware();
        $this->registerCloudwatchLogger();
        $this->registerNotificationLogging();
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/api-toolkit.php',
            'api-toolkit',
        );

        $this->replaceConfigRecursivelyFrom(
            __DIR__ . '/../config/logging.php',
            'logging',
        );

        $this->registerAutoDiscoveredMaps();

        $this->registerQueryParser();
    }

    /**
     * Load the package translation files.
     *
     * @return void
     */
    private function loadTranslationFiles(): void
    {
        $this->loadTranslationsFrom(
            __DIR__ . '/../resources/lang',
            'api-toolkit',
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
            return;
        }

        if (!function_exists('config_path')) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/api-toolkit.php' => config_path('api-toolkit.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_logs_table.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_logs_table.php'),
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
        Request::macro('includeTrashed', static fn (): bool => request()->query('include_trashed') === 'true');
        Request::macro('onlyTrashed', static fn (): bool => request()->query('only_trashed') === 'true');
    }

    /**
     * Register the export macros to the Request facade.
     *
     * @return void
     */
    private function registerExportMacros(): void
    {
        Request::macro('expectsExport', static fn (): bool => (bool) config('api-toolkit.exports.enabled')
            && (self::requestExpectsCsv() || self::requestExpectsXml()));

        Request::macro('expectsCsv', static fn (): bool => self::requestExpectsCsv());
        Request::macro('expectsXml', static fn (): bool => self::requestExpectsXml());
        Request::macro('expectsPdf', static fn (): bool => self::requestAcceptHeader() === 'application/pdf');
    }

    /**
     * Register the stream macros to the Request facade.
     *
     * @return void
     */
    private function registerStreamMacros(): void
    {
        Request::macro('expectsStream', static fn (): bool => self::requestAcceptHeader() === 'text/event-stream');
    }

    /**
     * Register any relevant middleware.
     *
     * @return void
     */
    private function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);
        $kernel = $this->app->make(Kernel::class);

        if (Config::get('api-toolkit.parser.register_middleware', true)) {
            $kernel->pushMiddleware(ParseApiQuery::class);
        }

        // Replace the existing maintenance mode middleware with our custom
        // implementation
        $global_middleware = $kernel->getGlobalMiddleware();

        foreach ($global_middleware as $key => $middleware) {
            if ($middleware === LaravelPreventRequestsDuringMaintenance::class) {
                $global_middleware[$key] = PreventRequestsDuringMaintenance::class;
            }
        }

        $kernel->setGlobalMiddleware($global_middleware);

        // Global middleware
        $kernel->pushMiddleware(JsonPrettyPrint::class);

        // Middleware aliases
        $router->aliasMiddleware('throttle', $this->getThrottleMiddleware());
    }

    /**
     * Return the throttle middleware that should be used.
     *
     * @return class-string
     */
    private function getThrottleMiddleware(): string
    {
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
        $this->app->singleton(Config::get('api-toolkit.parser.alias'), fn ($app) => new ApiQueryParser);
    }

    /**
     * Merge auto-discovered resource and repository maps into config.
     *
     * Manual configuration always takes precedence over discovered values.
     *
     * @return void
     */
    private function registerAutoDiscoveredMaps(): void
    {
        if (!Config::get('api-toolkit.auto_discovery.enabled', false)) {
            return;
        }

        $resource_map = Config::get('api-toolkit.resources.resource_map', []);
        $resource_map = is_array($resource_map) ? $resource_map : [];

        $discovered_resources = (new ResourceMapAutoDiscoverer($this->app))->discover();

        if ($discovered_resources !== []) {
            Config::set('api-toolkit.resources.resource_map', $resource_map + $discovered_resources);
        }

        $repository_map = Config::get('api-toolkit.repositories.repository_map', []);
        $repository_map = is_array($repository_map) ? $repository_map : [];

        $discovered_repositories = (new RepositoryMapAutoDiscoverer($this->app))->discover($repository_map);

        if ($discovered_repositories !== []) {
            Config::set('api-toolkit.repositories.repository_map', $repository_map + $discovered_repositories);
        }
    }

    /**
     * Determine whether the current request expects CSV export output.
     *
     * @return bool
     */
    private static function requestExpectsCsv(): bool
    {
        return self::requestAcceptHeader() === 'text/csv'
            && in_array('csv', (array) config('api-toolkit.exports.supported_formats', []), true);
    }

    /**
     * Determine whether the current request expects XML export output.
     *
     * @return bool
     */
    private static function requestExpectsXml(): bool
    {
        return self::requestAcceptHeader() === 'application/xml'
            && in_array('xml', (array) config('api-toolkit.exports.supported_formats', []), true);
    }

    /**
     * Get the current request's normalized Accept header.
     *
     * @return string
     */
    private static function requestAcceptHeader(): string
    {
        $accept_header = request()->header('Accept');

        if (is_array($accept_header)) {
            $accept_header = $accept_header[0] ?? '';
        }

        return strtolower((string) $accept_header);
    }
}
