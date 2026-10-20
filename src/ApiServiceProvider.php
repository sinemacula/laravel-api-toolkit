<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use SineMacula\ApiToolkit\Console\CacheResourcesCommand;
use SineMacula\ApiToolkit\Console\ClearResourcesCommand;
use SineMacula\ApiToolkit\Console\ExportOpenApiCommand;
use SineMacula\ApiToolkit\Console\GenerateDocsCommand;
use SineMacula\ApiToolkit\Console\InvalidateMetadataCommand;
use SineMacula\ApiToolkit\Console\ValidateSchemasCommand;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Providers\Registrars\ContainerBindingRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\LifecycleRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\LoggingRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\MiddlewareRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\RouteMacroRegistrar;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;

/**
 * API service provider.
 *
 * Thin coordinator that merges the package configuration, handles publishing
 * and schema validation, and delegates registration of middleware, logging,
 * lifecycle listeners, and container bindings to single-responsibility
 * registrars.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ApiServiceProvider extends ServiceProvider
{
    /** @var array<int, string> The console commands that boot without the discovery merge */
    private const array MERGE_SKIPPING_COMMANDS = [
        'config:cache',
        'optimize',
        'optimize:clear',
        'api-toolkit:resources:cache',
        'api-toolkit:resources:clear',
    ];

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->mergeDiscoveredResources();
        $this->loadTranslationFiles();
        $this->offerPublishing();
        $this->registerMorphMap();
        $this->validateSchemas();

        (new MiddlewareRegistrar($this->app))->register();
        (new LoggingRegistrar)->register();
        (new LifecycleRegistrar)->register();
        (new RouteMacroRegistrar)->register();
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
            GenerateDocsCommand::class,
            InvalidateMetadataCommand::class,
            CacheResourcesCommand::class,
            ClearResourcesCommand::class,
        ]);

        $this->optimizes(
            optimize: 'api-toolkit:resources:cache',
            clear: 'api-toolkit:resources:clear',
            key: 'api-toolkit',
        );
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
            __DIR__ . '/../resources/api-docs' => resource_path('api-docs'),
        ], 'api-toolkit-docs');
    }

    /**
     * Merge the attribute-discovered resources beneath the configured map.
     *
     * Discovery scans the configured resource paths for classes carrying the
     * ForModel attribute; an explicit resource_map entry wins over a discovered
     * binding, so the static map remains the canonical-resource tiebreak and
     * the seam for resources outside the scanned paths.
     *
     * The merge is skipped by a direct config:cache or optimize, so the config
     * cache they build holds no discovered bindings; a merge baked in by
     * another route is recomputed by the next merge. It is also skipped while
     * the compiled map is rebuilt or cleared, directly or by optimize:clear, so
     * a stale compiled map naming a resource that no longer resolves cannot
     * block them.
     *
     * @return void
     */
    private function mergeDiscoveredResources(): void
    {
        if (in_array($this->consoleCommand(), self::MERGE_SKIPPING_COMMANDS, true)) {
            return;
        }

        $this->app->make(ResourceDiscovery::class)->merge();
    }

    /**
     * The name of the console command being run, or null when there is none or
     * the application is not running in the console.
     *
     * The name is the first argument that is not an option, so global options
     * placed before it are skipped, along with the value of a space-separated
     * --env option. An argument value after the name is never matched.
     *
     * @return string|null
     */
    private function consoleCommand(): ?string
    {
        if (!$this->app->runningInConsole()) {
            return null;
        }

        /** @var array<int, string> $argv */
        $argv      = $_SERVER['argv'] ?? [];
        $arguments = array_slice($argv, 1);

        while ($arguments !== []) {

            $argument = array_shift($arguments);

            if ($argument === '--env') {
                array_shift($arguments);
            } elseif (!str_starts_with($argument, '-')) {
                return $argument;
            }
        }

        return null;
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
            ->mapWithKeys(function ($resource, $model): array {

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
