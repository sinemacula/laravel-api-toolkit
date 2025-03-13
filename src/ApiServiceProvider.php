<?php

namespace SineMacula\ApiToolkit;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as LaravelPreventRequestsDuringMaintenance;
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
        $this->registerExportMacros();
        $this->registerMiddleware();
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
            __DIR__ . '/../config/api-toolkit.php', 'api-toolkit'
        );

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
            __DIR__ . '/../resources/lang', 'api-toolkit'
        );

        $this->publishes([
            __DIR__ . '/../resources/lang' => resource_path('lang/vendor/api-toolkit')
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
            __DIR__ . '/../config/api-toolkit.php' => config_path('api-toolkit.php')
        ], 'config');

        $this->publishes([
            __DIR__ . '/../stubs/logs-table.stub' => database_path('migrations/' . date('Y_m_d_His') . '_create_logs_table.php')
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
     * Register the export macros to the Request facade.
     *
     * @return void
     */
    private function registerExportMacros(): void
    {
        Request::macro('expectsExport', function () {
            return config('api-toolkit.exports.enabled') && ($this->expectsCsv() || $this->expectsXml());
        });

        Request::macro('expectsCsv', function () {
            return strtolower($this->header('Accept')) === 'text/csv'
                && in_array('csv', config('api-toolkit.exports.supported_formats', []));
        });

        Request::macro('expectsXml', function () {
            return strtolower($this->header('Accept')) === 'application/xml'
                && in_array('xml', config('api-toolkit.exports.supported_formats', []));
        });
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
}
