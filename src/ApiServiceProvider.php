<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\Events\PublishingStubs;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SineMacula\ApiToolkit\Config\ResourceSettings;
use SineMacula\ApiToolkit\Config\ToolkitConfig;
use SineMacula\ApiToolkit\Config\ToolkitConfigValidator;
use SineMacula\ApiToolkit\Console\CacheResourcesCommand;
use SineMacula\ApiToolkit\Console\ClearResourcesCommand;
use SineMacula\ApiToolkit\Console\ExportOpenApiCommand;
use SineMacula\ApiToolkit\Console\GenerateDocsCommand;
use SineMacula\ApiToolkit\Console\InvalidateMetadataCommand;
use SineMacula\ApiToolkit\Console\MakeResourceCommand;
use SineMacula\ApiToolkit\Console\ValidateSchemasCommand;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Http\Resources\ResourceTypeIndex;
use SineMacula\ApiToolkit\Providers\Registrars\ContainerBindingRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\LifecycleRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\LoggingRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\MiddlewareRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\MorphMapRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\RouteMacroRegistrar;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;

/**
 * API service provider.
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

    /** @var array<int, string> The console commands that build the config cache */
    private const array CONFIG_CACHING_COMMANDS = ['config:cache', 'optimize'];

    /** @var string The console command that runs schema validation itself */
    private const string VALIDATING_COMMAND = 'api-toolkit:validate-schemas';

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->trackRunningCommands();
        $this->mergeDiscoveredResources();
        $this->loadTranslationFiles();
        $this->offerPublishing();
        (new MorphMapRegistrar)->register();
        $this->validateSchemas();
        $this->validateConfiguration();

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
        if (!$this->isConfigurationCached()) {
            ToolkitConfig::merge();
        }

        (new ContainerBindingRegistrar($this->app))->register();

        $this->commands([
            ValidateSchemasCommand::class,
            ExportOpenApiCommand::class,
            GenerateDocsCommand::class,
            InvalidateMetadataCommand::class,
            CacheResourcesCommand::class,
            ClearResourcesCommand::class,
            MakeResourceCommand::class,
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

        $this->publishes([
            __DIR__ . '/../config/api-toolkit.php' => config_path('api-toolkit.php'),
        ], 'api-toolkit-config');

        $this->publishes([
            __DIR__ . '/../resources/lang' => $this->app->langPath('vendor/api-toolkit'),
        ], 'api-toolkit-lang');

        $this->publishes([
            __DIR__ . '/../resources/api-docs' => resource_path('api-docs'),
        ], 'api-toolkit-docs');

        Event::listen(PublishingStubs::class, static function (PublishingStubs $event): void {
            $event->add(MakeResourceCommand::STUB, 'api-toolkit.resource.stub');
        });
    }

    /**
     * Merge the attribute-discovered resources beneath the configured map.
     *
     * Skipped for config caching and resource-map rebuilds, so the config cache
     * holds no discovered bindings and a stale compiled map cannot block them.
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
     * Record the console commands this process starts, so an application booted
     * while one runs can tell which.
     *
     * @return void
     */
    private function trackRunningCommands(): void
    {
        Event::listen(CommandStarting::class, static fn (CommandStarting $event) => RuntimeContext::enterCommand($event->command));
        Event::listen(CommandFinished::class, static fn () => RuntimeContext::leaveCommand());
    }

    /**
     * Return the name of the console command being run, if any.
     *
     * @return string|null
     */
    private function consoleCommand(): ?string
    {
        return $this->app->runningInConsole() ? $this->app->make(RuntimeContext::class)->command() : null;
    }

    /**
     * Determine whether the application booted from a cached configuration.
     *
     * @return bool
     */
    private function isConfigurationCached(): bool
    {
        return $this->app instanceof CachesConfiguration && $this->app->configurationIsCached();
    }

    /**
     * Validate every toolkit setting once the application has booted.
     *
     * Deferred so a setting written by a later provider is validated too. Run
     * when serving requests or queue jobs, and when building the config cache
     * from a fresh configuration, so a bad value aborts the build. Other
     * commands fail where they read a setting, so a broken one cannot block the
     * commands that repair it.
     *
     * @return void
     */
    private function validateConfiguration(): void
    {
        if (!$this->validatesConfiguration()) {
            return;
        }

        $this->app->booted(static function (): void {
            ToolkitConfigValidator::validate();
        });
    }

    /**
     * Determine whether this application validates its settings once booted.
     *
     * @return bool
     */
    private function validatesConfiguration(): bool
    {
        if (!$this->app->runningInConsole() || $this->app->make(RuntimeContext::class)->isRunningQueueWorker()) {
            return true;
        }

        return in_array($this->consoleCommand(), self::CONFIG_CACHING_COMMANDS, true) && !$this->isConfigurationCached();
    }

    /**
     * Validate all registered resource schemas.
     *
     * Skipped for the validation command, so it reports what it found itself,
     * and for the commands that boot without the discovered resources.
     *
     * @return void
     */
    private function validateSchemas(): void
    {
        $command = $this->consoleCommand();

        if (
            !ResourceSettings::shouldValidateSchemas()
            || $command === self::VALIDATING_COMMAND
            || in_array($command, self::MERGE_SKIPPING_COMMANDS, true)
        ) {
            return;
        }

        $resourceMap = ResourceSettings::bindings();

        if ($resourceMap === [] && ResourceTypeIndex::all() === []) {
            return;
        }

        $this->app->make(SchemaValidator::class)->validate($resourceMap);
    }
}
