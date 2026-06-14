<?php

namespace SineMacula\ApiToolkit;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use SineMacula\ApiToolkit\Console\ExportOpenApiCommand;
use SineMacula\ApiToolkit\Console\ValidateSchemasCommand;
use SineMacula\ApiToolkit\Providers\Registrars\ContainerBindingRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\LifecycleRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\LoggingRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\MiddlewareRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\RequestMacroRegistrar;
use SineMacula\ApiToolkit\Services\SchemaValidator;

/**
 * API service provider.
 *
 * Thin coordinator that merges the package configuration, handles publishing
 * and schema validation, and delegates registration of macros, middleware,
 * logging, lifecycle listeners, and container bindings to single-
 * responsibility registrars.
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

        (new RequestMacroRegistrar)->register();
        (new MiddlewareRegistrar($this->app))->register();
        (new LoggingRegistrar($this->app))->register();
        (new LifecycleRegistrar)->register();
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    #[\Override]
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

        (new ContainerBindingRegistrar($this->app))->register();

        $this->commands([
            ValidateSchemasCommand::class,
            ExportOpenApiCommand::class,
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
}
