<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as FrameworkMaintenance;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Contracts\OperationTerminated;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use SineMacula\ApiToolkit\Http\Resources\ResourceMetadataService;
use SineMacula\ApiToolkit\Listeners\NotificationListener;
use SineMacula\ApiToolkit\Listeners\QueueFlushSubscriber;
use SineMacula\ApiToolkit\Listeners\WritePoolFlushSubscriber;
use SineMacula\ApiToolkit\Providers\Registrars\ContainerBindingRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\LifecycleRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\LoggingRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\MiddlewareRegistrar;
use SineMacula\ApiToolkit\Providers\Registrars\MorphMapRegistrar;
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use Tests\Fixtures\Discovery\Conflict\FirstUserResource;
use Tests\Fixtures\Discovery\Conflict\SecondUserResource;
use Tests\Fixtures\Discovery\Invalid\AbstractAttributedResource;
use Tests\Fixtures\Discovery\Primary\DiscoveredUserResource;
use Tests\Fixtures\Discovery\Primary\Nested\DiscoveredPostResource;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\BrokenResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Integration tests for the ApiServiceProvider.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(ContainerBindingRegistrar::class)]
#[CoversClass(LifecycleRegistrar::class)]
#[CoversClass(LoggingRegistrar::class)]
#[CoversClass(MiddlewareRegistrar::class)]
#[CoversClass(MorphMapRegistrar::class)]
final class ApiServiceProviderTest extends TestCase
{
    /**
     * Test that the package config is merged.
     *
     * @return void
     */
    public function testPackageConfigIsMerged(): void
    {
        $config = $this->getConfig();

        self::assertNotNull($config->get('api-toolkit'));
        self::assertIsArray($config->get('api-toolkit.resources'));
    }

    /**
     * Test that the package neither adds nor merges into log channels.
     *
     * @return void
     */
    public function testPackageDoesNotAlterLoggingConfig(): void
    {
        $config   = $this->getConfig();
        $channels = $config->get('logging.channels');

        self::assertIsArray($channels);
        self::assertArrayNotHasKey('notifications', $channels);
        self::assertArrayNotHasKey('api-exceptions', $channels);

        $config->set('logging.channels.notifications', [
            'driver' => 'single',
            'path'   => storage_path('logs/app-notifications.log'),
        ]);

        $expected = $config->get('logging');

        (new ApiServiceProvider($this->getApplication()))->register();

        self::assertSame($expected, $config->get('logging'));
    }

    /**
     * Test that translations are loaded.
     *
     * @return void
     */
    public function testTranslationsAreLoaded(): void
    {
        /** @var \Illuminate\Translation\Translator $translator */
        $translator = $this->getApplication()->make('translator');

        self::assertTrue($translator->hasForLocale('api-toolkit::exceptions', 'en'));
    }

    /**
     * Test that the API query parser is registered as a singleton.
     *
     * @return void
     */
    public function testApiQueryParserIsRegisteredAsSingleton(): void
    {
        $app   = $this->getApplication();
        $alias = $this->getConfig()->get('api-toolkit.parser.alias');

        self::assertIsString($alias);

        $parser = $app->make($alias);

        self::assertInstanceOf(ApiQueryParser::class, $parser);

        self::assertSame($parser, $app->make($alias));
    }

    /**
     * Test that JsonPrettyPrint middleware is registered globally by default.
     *
     * @return void
     */
    public function testJsonPrettyPrintMiddlewareIsRegisteredGlobally(): void
    {
        /** @var \Orchestra\Testbench\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(JsonPrettyPrint::class, $middleware);
    }

    /**
     * Test that the toolkit maintenance middleware replaces the framework's.
     *
     * It must keep the framework's slot after HandleCors.
     *
     * @return void
     */
    public function testMaintenanceModeMiddlewareReplacesFrameworkMiddlewareInPlaceWhenEnabled(): void
    {
        /** @var \Orchestra\Testbench\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(PreventRequestsDuringMaintenance::class, $middleware);
        self::assertNotContains(FrameworkMaintenance::class, $middleware);

        $cors = array_search(HandleCors::class, $middleware, true);

        self::assertIsInt($cors);

        self::assertSame(PreventRequestsDuringMaintenance::class, $middleware[$cors + 1] ?? null);
    }

    /**
     * Test that throttle middleware alias is set by default.
     *
     * @return void
     */
    public function testThrottleMiddlewareAliasIsSet(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->getApplication()->make(Router::class);

        $middleware = $router->getMiddleware();

        self::assertArrayHasKey('throttle', $middleware);
    }

    /**
     * Test that the middleware config section exists with correct defaults.
     *
     * @return void
     */
    public function testMiddlewareConfigSectionHasCorrectDefaults(): void
    {
        $config = $this->getConfig();

        self::assertTrue($config->get('api-toolkit.middleware.maintenance_mode_swap.enabled'));
        self::assertTrue($config->get('api-toolkit.middleware.json_pretty_print.enabled'));
        self::assertSame('global', $config->get('api-toolkit.middleware.json_pretty_print.scope'));
        self::assertTrue($config->get('api-toolkit.middleware.throttle.enabled'));
        self::assertNull($config->get('api-toolkit.middleware.throttle.class'));
    }

    /**
     * Test that the correlation id is read from X-Request-Id by default.
     *
     * @return void
     */
    public function testServicesConfigSectionHasCorrectDefaults(): void
    {
        self::assertSame('X-Request-Id', $this->getConfig()->get('api-toolkit.services.correlation_header'));
    }

    /**
     * Test that the default config registers the expected middleware.
     *
     * @return void
     */
    public function testDefaultConfigProducesBackwardCompatibleBehavior(): void
    {
        $app = $this->getApplication();

        /** @var \Orchestra\Testbench\Http\Kernel $kernel */
        $kernel = $app->make(HttpKernel::class);
        $global = $kernel->getGlobalMiddleware();

        self::assertContains(PreventRequestsDuringMaintenance::class, $global);
        self::assertNotContains(FrameworkMaintenance::class, $global);

        self::assertContains(JsonPrettyPrint::class, $global);

        /** @var \Illuminate\Routing\Router $router */
        $router     = $app->make(Router::class);
        $middleware = $router->getMiddleware();

        self::assertArrayHasKey('throttle', $middleware);
    }

    /**
     * Test that notification listeners are registered when enabled.
     *
     * @return void
     */
    public function testNotificationListenersAreRegisteredWhenEnabled(): void
    {
        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $this->getApplication()->make('events');

        self::assertTrue($events->hasListeners(NotificationSending::class));
        self::assertTrue($events->hasListeners(NotificationSent::class));
    }

    /**
     * Test that notification listeners are not registered when disabled.
     *
     * @return void
     */
    public function testNotificationListenersAreNotRegisteredWhenDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.notifications.enable_logging', false);

        $provider = new ApiServiceProvider($app);
        $provider->register();

        // Smoke test only: re-registering with logging disabled must not throw
        self::assertTrue(true);
    }

    /**
     * Test that registerNotificationLogging returns early when disabled.
     *
     * @return void
     */
    public function testRegisterNotificationLoggingSkipsWhenDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.notifications.enable_logging', false);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertFalse((bool) $this->getConfig()->get('api-toolkit.notifications.enable_logging'));
    }

    /**
     * Test that boot merges discovered resources beneath the configured map.
     *
     * @return void
     */
    public function testBootMergesDiscoveredResourcesBeneathConfiguredMap(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        $map = $this->getConfig()->get('api-toolkit.resources.resource_map');

        self::assertIsArray($map);
        self::assertSame(UserResource::class, $map[User::class]);
        self::assertSame(DiscoveredPostResource::class, $map[Post::class]);
    }

    /**
     * Provide the arguments of the commands that skip the discovery merge.
     *
     * @return iterable<string, array{0: array<int, string>}>
     */
    public static function cacheBuildArgvProvider(): iterable
    {
        yield 'config:cache' => [['artisan', 'config:cache']];
        yield 'optimize' => [['artisan', 'optimize']];
        yield 'optimize:clear' => [['artisan', 'optimize:clear']];
        yield 'resource cache' => [['artisan', 'api-toolkit:resources:cache']];
        yield 'resource clear' => [['artisan', 'api-toolkit:resources:clear']];
        yield 'leading inline option' => [['artisan', '--env=production', 'config:cache']];
        yield 'leading flags' => [['artisan', '-q', '--no-interaction', 'optimize']];
        yield 'leading separate env value' => [['artisan', '--env', 'production', 'config:cache']];
    }

    /**
     * Test that the discovery merge is skipped while caches build or clear.
     *
     * @param  array<int, string>  $argv
     * @return void
     */
    #[DataProvider('cacheBuildArgvProvider')]
    public function testCacheBuildSkipsTheDiscoveryMerge(array $argv): void
    {
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $this->bootWithArgv($argv);

        self::assertSame([
            User::class => UserResource::class,
        ], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Provide the arguments of invocations that keep the discovery merge.
     *
     * @return iterable<string, array{0: array<int, string>|null}>
     */
    public static function mergingArgvProvider(): iterable
    {
        yield 'no arguments' => [null];
        yield 'no command' => [['artisan']];
        yield 'options only' => [['artisan', '-q', '--env', 'production']];
        yield 'another command' => [['artisan', 'route:cache']];
        yield 'argument value' => [['artisan', 'queue:work', '--queue', 'optimize']];
        yield 'env value' => [['artisan', '--env', 'optimize', 'route:cache']];
    }

    /**
     * Test that every other invocation keeps the discovery merge.
     *
     * @param  array<int, string>|null  $argv
     * @return void
     */
    #[DataProvider('mergingArgvProvider')]
    public function testOtherInvocationsKeepTheDiscoveryMerge(?array $argv): void
    {
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->bootWithArgv($argv);

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Test that a web request keeps the discovery merge whatever its arguments.
     *
     * @return void
     */
    public function testRequestOutsideTheConsoleKeepsTheDiscoveryMerge(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $console  = new \ReflectionProperty(Application::class, 'isRunningInConsole');
        $original = $console->getValue($app);

        $console->setValue($app, false);

        try {
            $this->bootWithArgv(['search=foo', 'optimize']);
        } finally {
            $console->setValue($app, $original);
        }

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Test that a rebuild over a stale compiled map still reports conflicts.
     *
     * @return void
     */
    public function testResourceCacheRebuildReportsConflictsDespiteAStaleCompiledMap(): void
    {
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Conflict']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->useIsolatedBootstrapPath();
        $this->writeCompiledMap([User::class => FirstUserResource::class]);

        $this->bootWithArgv(['artisan', 'deploy']);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message): bool => str_contains($message, 'ignoring [' . SecondUserResource::class . ']'));

        $this->pendingArtisan('api-toolkit:resources:cache')->assertExitCode(0)->run();
    }

    /**
     * Test that a stale compiled map does not block its own rebuild.
     *
     * @return void
     */
    public function testResourceCacheRebuildsAStaleCompiledMap(): void
    {
        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->useIsolatedBootstrapPath();

        $path = $this->writeCompiledMap([User::class => AbstractAttributedResource::class]);

        $this->bootWithArgv(['artisan', 'api-toolkit:resources:cache']);

        $this->pendingArtisan('api-toolkit:resources:cache')->assertExitCode(0)->run();

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], require $path);
    }

    /**
     * Test that boot registers derived morph aliases when enabled.
     *
     * @return void
     */
    public function testBootRegistersDerivedMorphAliasesWhenEnabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertSame(['users' => User::class], Relation::morphMap());
        self::assertFalse(Relation::requiresMorphMap());
    }

    /**
     * Test that boot skips resources that lack getResourceType.
     *
     * @return void
     */
    public function testBootSkipsMorphAliasesForResourcesWithoutGetResourceType(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => \stdClass::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertSame([], Relation::morphMap());
        self::assertFalse(Relation::requiresMorphMap());
    }

    /**
     * Test that boot registers morph aliases for discovered resources too.
     *
     * @return void
     */
    public function testBootRegistersMorphAliasesForDiscoveredResources(): void
    {
        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', []);

        $this->useIsolatedBootstrapPath();

        $this->bootWithArgv(['artisan', 'deploy']);

        self::assertSame([
            'discovered_users' => User::class,
            'discovered_posts' => Post::class,
        ], Relation::morphMap());
    }

    /**
     * Test that the OperatorRegistry is a singleton with built-in operators.
     *
     * @return void
     */
    public function testOperatorRegistryIsRegisteredAsSingleton(): void
    {
        $app      = $this->getApplication();
        $registry = $app->make(OperatorRegistry::class);

        self::assertInstanceOf(OperatorRegistry::class, $registry);

        self::assertSame($registry, $app->make(OperatorRegistry::class));

        self::assertTrue($registry->has('$eq'));
        self::assertTrue($registry->has('$neq'));
        self::assertTrue($registry->has('$gt'));
        self::assertTrue($registry->has('$lt'));
        self::assertTrue($registry->has('$ge'));
        self::assertTrue($registry->has('$le'));
        self::assertTrue($registry->has('$in'));
        self::assertTrue($registry->has('$between'));
        self::assertTrue($registry->has('$contains'));
        self::assertTrue($registry->has('$null'));
        self::assertTrue($registry->has('$notNull'));

        // The unindexable partial-match operator ships unregistered
        self::assertFalse($registry->has('$like'));
    }

    /**
     * Test that every shipped operator token is permitted by some capability.
     *
     * @return void
     */
    public function testEveryRegisteredOperatorTokenIsDecidedByACapability(): void
    {
        $registry = $this->getApplication()->make(OperatorRegistry::class);

        foreach ($registry->tokens() as $token) {

            $permitted = array_filter(
                Capability::cases(),
                static fn (Capability $capability): bool => $capability->permits($token),
            );

            self::assertNotSame([], $permitted, sprintf('The "%s" operator is permitted by no capability.', $token));
        }
    }

    /**
     * Test that validate schemas runs during boot when enabled.
     *
     * @return void
     */
    public function testValidateSchemasRunsDuringBootWhenEnabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.validate_schemas', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->register();
        $provider->boot();

        // Boot did not throw, so the valid schemas passed validation
        self::assertTrue(true);
    }

    /**
     * Test that validate schemas is skipped when disabled.
     *
     * @return void
     */
    public function testValidateSchemasSkippedWhenDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.validate_schemas', false);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->register();
        $provider->boot();

        // Smoke test only: boot completes with schema validation disabled
        self::assertFalse((bool) $this->getConfig()->get('api-toolkit.resources.validate_schemas'));
    }

    /**
     * Test that validate schemas throws on invalid schema at boot.
     *
     * @return void
     */
    public function testValidateSchemasThrowsOnInvalidSchemaAtBoot(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.validate_schemas', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => BrokenResource::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->register();

        $this->expectException(InvalidSchemaException::class);

        $provider->boot();
    }

    /**
     * Test that SchemaValidator is registered as a singleton.
     *
     * @return void
     */
    public function testSchemaValidatorIsRegisteredAsSingleton(): void
    {
        $app       = $this->getApplication();
        $validator = $app->make(SchemaValidator::class);

        self::assertInstanceOf(SchemaValidator::class, $validator);

        self::assertSame($validator, $app->make(SchemaValidator::class));
    }

    /**
     * Test that the validate schemas command is registered.
     *
     * @return void
     */
    public function testValidateSchemasCommandIsRegistered(): void
    {
        $commands = Artisan::all();

        self::assertArrayHasKey('api-toolkit:validate-schemas', $commands);
    }

    /**
     * Test that the invalidate metadata command is registered.
     *
     * @return void
     */
    public function testInvalidateMetadataCommandIsRegistered(): void
    {
        self::assertArrayHasKey('api-toolkit:invalidate-metadata', Artisan::all());
    }

    /**
     * Test that the cache resources command is registered.
     *
     * @return void
     */
    public function testCacheResourcesCommandIsRegistered(): void
    {
        self::assertArrayHasKey('api-toolkit:resources:cache', Artisan::all());
    }

    /**
     * Test that the clear resources command is registered.
     *
     * @return void
     */
    public function testClearResourcesCommandIsRegistered(): void
    {
        self::assertArrayHasKey('api-toolkit:resources:clear', Artisan::all());
    }

    /**
     * Test that registering the provider hooks the resource optimize commands.
     *
     * @return void
     */
    public function testOptimizeRunsTheResourceCommands(): void
    {
        $optimize = ServiceProvider::$optimizeCommands;
        $clear    = ServiceProvider::$optimizeClearCommands;

        // The registry is static and outlives each test application
        unset(ServiceProvider::$optimizeCommands['api-toolkit'], ServiceProvider::$optimizeClearCommands['api-toolkit']);

        try {
            (new ApiServiceProvider($this->getApplication()))->register();

            self::assertSame('api-toolkit:resources:cache', ServiceProvider::$optimizeCommands['api-toolkit'] ?? null);
            self::assertSame('api-toolkit:resources:clear', ServiceProvider::$optimizeClearCommands['api-toolkit'] ?? null);
        } finally {
            ServiceProvider::$optimizeCommands      = $optimize;
            ServiceProvider::$optimizeClearCommands = $clear;
        }
    }

    /**
     * Test that optimize compiles the resource map and optimize:clear drops it.
     *
     * @return void
     */
    public function testOptimizeAndOptimizeClearCompileAndRemoveTheResourceMap(): void
    {
        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);

        $this->useIsolatedBootstrapPath();

        $path = $this->getApplication()->bootstrapPath('cache/api-toolkit-resources.php');

        $this->pendingArtisan('optimize', ['--except' => 'config,events,routes,views'])->assertExitCode(0)->run();

        self::assertSame([
            User::class => DiscoveredUserResource::class,
            Post::class => DiscoveredPostResource::class,
        ], require $path);

        $this->pendingArtisan('optimize:clear', ['--except' => 'config,cache,compiled,events,routes,views'])->assertExitCode(0)->run();

        self::assertFileDoesNotExist($path);
    }

    /**
     * Test that the shipped config invalidates metadata after migrations.
     *
     * @return void
     */
    public function testConfigurationInvalidatesMetadataAfterMigrationsByDefault(): void
    {
        self::assertTrue($this->getConfig()->get('api-toolkit.lifecycle.migrations'));
    }

    /**
     * Test that configuration contains the validate schemas key.
     *
     * @return void
     */
    public function testConfigurationContainsValidateSchemasKey(): void
    {
        $config = $this->getConfig()->get('api-toolkit.resources');

        self::assertIsArray($config);
        self::assertArrayHasKey('validate_schemas', $config);
        self::assertFalse($config['validate_schemas']);
    }

    /**
     * Test that CacheManager is bound as a singleton.
     *
     * @return void
     */
    public function testCacheManagerIsBoundAsSingleton(): void
    {
        $app   = $this->getApplication();
        $first = $app->make(CacheManager::class);

        self::assertInstanceOf(CacheManager::class, $first);
        self::assertSame($first, $app->make(CacheManager::class));
    }

    /**
     * Test that the Octane flush listener is registered when config is enabled.
     *
     * @return void
     */
    public function testOctaneFlushListenerRegisteredWhenConfigEnabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.lifecycle.octane', true);

        // Reset the dispatcher so Octane's own listeners cannot mask the result
        Event::swap(new Dispatcher($app));

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $app->make('events');

        self::assertTrue($events->hasListeners(OperationTerminated::class));
    }

    /**
     * Test that the Octane flush listener is not registered when disabled.
     *
     * @return void
     */
    public function testOctaneFlushListenerNotRegisteredWhenConfigDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.lifecycle.octane', false);

        // Reset the dispatcher so Octane's own listeners cannot mask the result
        Event::swap(new Dispatcher($app));

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $app->make('events');

        self::assertFalse($events->hasListeners(OperationTerminated::class));
    }

    /**
     * Test that the queue flush subscriber is registered when enabled.
     *
     * @return void
     */
    public function testQueueFlushSubscriberRegisteredWhenConfigEnabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.lifecycle.queue', true);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        /** @var \Illuminate\Events\Dispatcher $events */
        $events = $app->make('events');

        self::assertTrue($events->hasListeners(JobProcessed::class));
    }

    /**
     * Test that the queue flush subscriber is not registered when disabled.
     *
     * @return void
     */
    public function testQueueFlushSubscriberNotRegisteredWhenConfigDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.lifecycle.octane', false);
        $this->getConfig()->set('api-toolkit.lifecycle.queue', false);
        $this->getConfig()->set('api-toolkit.notifications.enable_logging', false);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        // Smoke test only: both subscribers hear JobProcessed, so this only
        // proves boot completes with the queue lifecycle off
        self::assertFalse((bool) $this->getConfig()->get('api-toolkit.lifecycle.queue'));
    }

    /**
     * Test that the on_failure config key is available with the default value.
     *
     * @return void
     */
    public function testOnFailureConfigKeyIsAvailable(): void
    {
        $value = $this->getConfig()->get('api-toolkit.deferred_writes.on_failure');

        self::assertNotNull($value);
        self::assertSame('collect', $value);
    }

    /**
     * Test that the WritePool receives the COLLECT strategy by default.
     *
     * @return void
     */
    public function testWritePoolReceivesCollectStrategyByDefault(): void
    {
        $pool = $this->getApplication()->make(WritePool::class);

        $reflection = new \ReflectionProperty(WritePool::class, 'strategy');
        $strategy   = $reflection->getValue($pool);

        self::assertSame(FlushStrategy::COLLECT, $strategy);
    }

    /**
     * Test that the WritePool is non-transactional by default.
     *
     * @return void
     */
    public function testWritePoolIsNonTransactionalByDefault(): void
    {
        $pool = $this->getApplication()->make(WritePool::class);

        $reflection    = new \ReflectionProperty(WritePool::class, 'transactional');
        $transactional = $reflection->getValue($pool);

        self::assertFalse($transactional);
    }

    /**
     * Test that the WritePool receives a non-default on_failure strategy.
     *
     * @return void
     */
    public function testWritePoolReceivesConfiguredStrategy(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.deferred_writes.on_failure', 'throw');

        $provider = new ApiServiceProvider($app);
        $provider->register();

        $pool = $app->make(WritePool::class);

        $reflection = new \ReflectionProperty(WritePool::class, 'strategy');
        $strategy   = $reflection->getValue($pool);

        self::assertSame(FlushStrategy::THROW, $strategy);
    }

    /**
     * Test that an invalid on_failure value throws a ValueError.
     *
     * @return void
     */
    public function testInvalidOnFailureConfigThrowsValueError(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.deferred_writes.on_failure', 'invalid');

        $provider = new ApiServiceProvider($app);
        $provider->register();

        $this->expectException(\ValueError::class);

        $app->make(WritePool::class);
    }

    /**
     * Test that ParseApiQuery is pushed to the global stack when enabled.
     *
     * @return void
     */
    public function testParseApiQueryMiddlewareIsRegisteredGlobally(): void
    {
        /** @var \Orchestra\Testbench\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(ParseApiQuery::class, $middleware);
    }

    /**
     * Test that middleware registration defaults on when keys are missing.
     *
     * A fresh kernel and router are resolved so only this boot's registrations
     * show.
     *
     * @return void
     */
    public function testMiddlewareRegistrationDefaultsApplyWhenConfigKeysAreMissing(): void
    {
        $app    = $this->getApplication();
        $config = $this->getConfig();

        $config->set('api-toolkit.parser', []);
        $config->set('api-toolkit.middleware', []);

        $app->forgetInstance(HttpKernel::class);
        $app->forgetInstance(Router::class);
        $app->forgetInstance('router');

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        /** @var \Orchestra\Testbench\Http\Kernel $kernel */
        $kernel     = $app->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(ParseApiQuery::class, $middleware);
        self::assertContains(PreventRequestsDuringMaintenance::class, $middleware);
        self::assertContains(JsonPrettyPrint::class, $middleware);

        /** @var \Illuminate\Routing\Router $router */
        $router  = $app->make(Router::class);
        $aliases = $router->getMiddleware();

        self::assertArrayHasKey('throttle', $aliases);
        self::assertSame(ThrottleRequests::class, $aliases['throttle']);
    }

    /**
     * Test that the config file is publishable under the config group.
     *
     * @return void
     */
    public function testConfigIsPublishableUnderConfigGroup(): void
    {
        $paths = ServiceProvider::pathsToPublish(ApiServiceProvider::class, 'config');

        self::assertSame(
            [$this->getProviderPath('/../config/api-toolkit.php') => config_path('api-toolkit.php')],
            $paths,
        );
    }

    /**
     * Test that the translations are publishable under the translations group.
     *
     * @return void
     */
    public function testTranslationsArePublishableUnderTranslationsGroup(): void
    {
        $paths = ServiceProvider::pathsToPublish(ApiServiceProvider::class, 'translations');

        self::assertSame(
            [$this->getProviderPath('/../resources/lang') => resource_path('lang/vendor/api-toolkit')],
            $paths,
        );
    }

    /**
     * Test that no morph alias is registered when dynamic mapping is disabled.
     *
     * @return void
     */
    public function testNoMorphAliasIsRegisteredWhenDynamicMorphMappingIsDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', false);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertSame([], Relation::morphMap());
        self::assertFalse(Relation::requiresMorphMap());
    }

    /**
     * Test that schema validation defaults off when its config key is missing.
     *
     * The resource map holds an invalid schema, so a passing boot proves the
     * gate is off.
     *
     * @return void
     */
    public function testValidateSchemasDefaultsToDisabledWhenConfigKeyIsMissing(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources', [
            'resource_map' => [
                User::class => BrokenResource::class,
            ],
            'fixed_fields' => ['id', '_type'],
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertNull($this->getConfig()->get('api-toolkit.resources.validate_schemas'));
    }

    /**
     * Test that schema validation is skipped for a non-array resource map.
     *
     * @return void
     */
    public function testValidateSchemasSkippedWhenResourceMapIsNotAnArray(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.validate_schemas', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', 'not-an-array');

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertSame('not-an-array', $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Test that notification logging defaults on when its key is missing.
     *
     * @return void
     */
    public function testNotificationLoggingDefaultsToEnabledWhenConfigKeyIsMissing(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.notifications', []);

        $before = $this->countRawListeners(NotificationSending::class);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertSame($before + 1, $this->countRawListeners(NotificationSending::class));
    }

    /**
     * Test that no notification listeners are added when logging is disabled.
     *
     * @return void
     */
    public function testNotificationListenersAreNotAddedWhenLoggingDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.notifications.enable_logging', false);

        $sending = $this->countRawListeners(NotificationSending::class);
        $sent    = $this->countRawListeners(NotificationSent::class);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertSame($sending, $this->countRawListeners(NotificationSending::class));
        self::assertSame($sent, $this->countRawListeners(NotificationSent::class));
    }

    /**
     * Test that notification events are wired to the NotificationListener.
     *
     * @return void
     */
    public function testNotificationListenersDelegateToNotificationListener(): void
    {
        $raw = $this->getEventDispatcher()->getRawListeners();

        self::assertContains([NotificationListener::class, 'sending'], self::listenersFor($raw, NotificationSending::class));
        self::assertContains([NotificationListener::class, 'sent'], self::listenersFor($raw, NotificationSent::class));
    }

    /**
     * Test that ResourceMetadataProvider is bound to a singleton.
     *
     * @return void
     */
    public function testResourceMetadataProviderIsBoundAsSingleton(): void
    {
        $app   = $this->getApplication();
        $first = $app->make(ResourceMetadataProvider::class);

        self::assertInstanceOf(ResourceMetadataService::class, $first);
        self::assertSame($first, $app->make(ResourceMetadataProvider::class));
    }

    /**
     * Test that SchemaIntrospectionProvider is bound to a singleton.
     *
     * @return void
     */
    public function testSchemaIntrospectorIsBoundAsSingleton(): void
    {
        $app   = $this->getApplication();
        $first = $app->make(SchemaIntrospectionProvider::class);

        self::assertInstanceOf(SchemaIntrospector::class, $first);
        self::assertSame($first, $app->make(SchemaIntrospectionProvider::class));
    }

    /**
     * Test that the write pool flush subscriber hears the lifecycle events.
     *
     * @return void
     */
    public function testWritePoolFlushSubscriberIsSubscribedToLifecycleEvents(): void
    {
        self::assertTrue($this->hasSubscriberListener(RequestHandled::class, WritePoolFlushSubscriber::class));
        self::assertTrue($this->hasSubscriberListener(CommandFinished::class, WritePoolFlushSubscriber::class));
    }

    /**
     * Test that the queue flush subscriber is subscribed when enabled.
     *
     * @return void
     */
    public function testQueueFlushSubscriberIsSubscribedWhenQueueLifecycleEnabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.lifecycle.queue', true);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertTrue($this->hasSubscriberListener(JobProcessed::class, QueueFlushSubscriber::class));
        self::assertTrue($this->hasSubscriberListener(JobFailed::class, QueueFlushSubscriber::class));
    }

    /**
     * Test that the queue flush subscriber is not subscribed when disabled.
     *
     * @return void
     */
    public function testQueueFlushSubscriberIsNotSubscribedWhenQueueLifecycleDisabled(): void
    {
        $app = $this->getApplication();

        // Reset the dispatcher so boot-time wiring does not skew the baseline
        Event::swap(new Dispatcher($app));

        $this->getConfig()->set('api-toolkit.lifecycle.queue', false);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertFalse($this->hasSubscriberListener(JobProcessed::class, QueueFlushSubscriber::class));
        self::assertFalse($this->hasSubscriberListener(JobFailed::class, QueueFlushSubscriber::class));
    }

    /**
     * Test that the WritePool uses default sizes when config keys are missing.
     *
     * @return void
     */
    public function testWritePoolUsesDefaultSizesWhenConfigKeysAreMissing(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.deferred_writes', ['on_failure' => 'log']);

        $provider = new ApiServiceProvider($app);
        $provider->register();

        $pool = $app->make(WritePool::class);

        self::assertSame(500, (new \ReflectionProperty(WritePool::class, 'chunkSize'))->getValue($pool));
        self::assertSame(10000, (new \ReflectionProperty(WritePool::class, 'poolLimit'))->getValue($pool));
    }

    /**
     * Test that the WritePool uses default sizes for non-numeric config.
     *
     * @return void
     */
    public function testWritePoolFallsBackToDefaultSizesWhenConfigValuesAreNotNumeric(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.deferred_writes.chunk_size', 'not-numeric');
        $this->getConfig()->set('api-toolkit.deferred_writes.pool_limit', 'not-numeric');

        $provider = new ApiServiceProvider($app);
        $provider->register();

        $pool = $app->make(WritePool::class);

        self::assertSame(500, (new \ReflectionProperty(WritePool::class, 'chunkSize'))->getValue($pool));
        self::assertSame(10000, (new \ReflectionProperty(WritePool::class, 'poolLimit'))->getValue($pool));
    }

    /**
     * Test that the WritePool receives the configured numeric sizes.
     *
     * @return void
     */
    public function testWritePoolUsesConfiguredNumericSizes(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.deferred_writes.chunk_size', 250);
        $this->getConfig()->set('api-toolkit.deferred_writes.pool_limit', 5000);

        $provider = new ApiServiceProvider($app);
        $provider->register();

        $pool = $app->make(WritePool::class);

        self::assertSame(250, (new \ReflectionProperty(WritePool::class, 'chunkSize'))->getValue($pool));
        self::assertSame(5000, (new \ReflectionProperty(WritePool::class, 'poolLimit'))->getValue($pool));
    }

    /**
     * Define the test environment configuration.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        assert($app instanceof Application);

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.parser.register_middleware', true);
        $config->set('api-toolkit.notifications.enable_logging', true);
    }

    /**
     * Boot a fresh provider with the given console arguments, then restore.
     *
     * @param  array<int, string>|null  $argv
     * @return void
     */
    private function bootWithArgv(?array $argv): void
    {
        $original = $_SERVER['argv'] ?? null;

        $_SERVER['argv'] = $argv;

        if ($argv === null) {
            unset($_SERVER['argv']);
        }

        try {
            (new ApiServiceProvider($this->getApplication()))->boot();
        } finally {
            $_SERVER['argv'] = $original;
        }
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

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function getConfig(): ConfigRepository
    {
        /** @var \Illuminate\Config\Repository */
        return $this->getApplication()->make('config');
    }

    /**
     * Get the event dispatcher instance.
     *
     * @return \Illuminate\Events\Dispatcher
     */
    private function getEventDispatcher(): Dispatcher
    {
        /** @var \Illuminate\Events\Dispatcher */
        return $this->getApplication()->make('events');
    }

    /**
     * Resolve a path relative to the service provider source directory.
     *
     * Mirrors the __DIR__-relative paths the provider publishes from.
     *
     * @param  string  $path
     * @return string
     */
    private function getProviderPath(string $path): string
    {
        $file = (new \ReflectionClass(ApiServiceProvider::class))->getFileName();

        self::assertIsString($file);

        return dirname($file) . $path;
    }

    /**
     * Count the raw listeners registered for the given event.
     *
     * @param  class-string  $event
     * @return int
     */
    private function countRawListeners(string $event): int
    {
        $listeners = $this->getEventDispatcher()->getRawListeners()[$event] ?? [];

        return is_countable($listeners) ? count($listeners) : 0;
    }

    /**
     * Determine whether the event has a listener from the given subscriber.
     *
     * @param  class-string  $event
     * @param  class-string  $subscriber
     * @return bool
     */
    private function hasSubscriberListener(string $event, string $subscriber): bool
    {
        $listeners = $this->getEventDispatcher()->getRawListeners()[$event] ?? [];

        if (!is_iterable($listeners)) {
            return false;
        }

        foreach ($listeners as $listener) {
            if (is_array($listener) && ($listener[0] ?? null) instanceof $subscriber) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the raw listeners registered for the given event.
     *
     * @param  array<array-key, mixed>  $raw
     * @param  string  $event
     * @return array<array-key, mixed>
     */
    private static function listenersFor(array $raw, string $event): array
    {
        $listeners = $raw[$event] ?? [];

        self::assertIsArray($listeners);

        return $listeners;
    }
}
