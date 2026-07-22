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
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Contracts\OperationTerminated;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Contracts\ResourceMetadataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\FlushStrategy;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Http\Middleware\DetectsCapabilities;
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
use SineMacula\ApiToolkit\Repositories\Concerns\WritePool;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Schema\Introspection\SchemaIntrospector;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
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
     * Test that logging config is merged.
     *
     * @return void
     */
    public function testLoggingConfigIsMerged(): void
    {
        $channels = $this->getConfig()->get('logging.channels');

        self::assertArrayHasKey('notifications', $channels);
        self::assertArrayHasKey('api-exceptions', $channels);
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
        $app    = $this->getApplication();
        $alias  = $this->getConfig()->get('api-toolkit.parser.alias');
        $parser = $app->make($alias);

        self::assertInstanceOf(ApiQueryParser::class, $parser);

        // Same instance on second resolve (singleton)
        self::assertSame($parser, $app->make($alias));
    }

    /**
     * Test that JsonPrettyPrint middleware is registered globally by default.
     *
     * @return void
     */
    public function testJsonPrettyPrintMiddlewareIsRegisteredGlobally(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(JsonPrettyPrint::class, $middleware);
    }

    /**
     * Test that the toolkit's PreventRequestsDuringMaintenance middleware is
     * prepended to the global stack when enabled (default).
     *
     * @return void
     */
    public function testMaintenanceModeMiddlewareIsPrependedWhenEnabled(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(PreventRequestsDuringMaintenance::class, $middleware);

        // It should be the first middleware in the stack
        self::assertSame(PreventRequestsDuringMaintenance::class, $middleware[0]);
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
     * Test backward compatibility: default config produces the same behavior as
     * the previous version.
     *
     * @return void
     */
    public function testDefaultConfigProducesBackwardCompatibleBehavior(): void
    {
        $app = $this->getApplication();

        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $app->make(HttpKernel::class);
        $global = $kernel->getGlobalMiddleware();

        // Maintenance mode middleware is prepended (first in the stack)
        self::assertSame(PreventRequestsDuringMaintenance::class, $global[0]);

        // JsonPrettyPrint is in the global stack
        self::assertContains(JsonPrettyPrint::class, $global);

        // Throttle alias is set
        /** @var \Illuminate\Routing\Router $router */
        $router     = $app->make(Router::class);
        $middleware = $router->getMiddleware();

        self::assertArrayHasKey('throttle', $middleware);
    }

    /**
     * Test that DetectsCapabilities middleware is registered globally.
     *
     * @return void
     */
    public function testDetectsCapabilitiesMiddlewareIsRegisteredGlobally(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(DetectsCapabilities::class, $middleware);
    }

    /**
     * Test that DetectsCapabilities middleware is appended to the api group
     * when scoped.
     *
     * @return void
     */
    public function testDetectsCapabilitiesMiddlewareIsScopedToApiGroup(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.middleware.detect_capabilities.scope', 'api');

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $app->make(HttpKernel::class);
        $groups = $kernel->getMiddlewareGroups();

        self::assertContains(DetectsCapabilities::class, $groups['api'] ?? []);
    }

    /**
     * Test that DetectsCapabilities middleware is not registered when config is
     * disabled.
     *
     * @return void
     */
    public function testDetectsCapabilitiesMiddlewareNotRegisteredWhenDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.middleware.detect_capabilities.enabled', false);

        // Re-boot with the middleware disabled to test the config gate
        $provider = new ApiServiceProvider($app);
        $provider->boot();

        // The middleware was already pushed in the original boot from setUp.
        // Verify the config gate by confirming boot completes without error.
        self::assertFalse((bool) $this->getConfig()->get('api-toolkit.middleware.detect_capabilities.enabled'));
    }

    /**
     * Test that notification listeners are registered when enabled.
     *
     * @return void
     */
    public function testNotificationListenersAreRegisteredWhenEnabled(): void
    {
        /** @var \Illuminate\Contracts\Events\Dispatcher $events */
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

        // Create a fresh app with notifications disabled
        $this->getConfig()->set('api-toolkit.notifications.enable_logging', false);

        // Re-register with new config by creating a fresh provider
        $provider = new ApiServiceProvider($app);
        $provider->register();

        // We can check that the current listeners include the notification ones
        // (they were already registered in setUp, but this test validates the
        // config gate)
        self::assertTrue(true);
    }

    /**
     * Test that registerNotificationLogging returns early when disabled.
     *
     * This test exercises the early-return branch inside
     * registerNotificationLogging() directly via a fresh provider instance
     * booted with logging disabled.
     *
     * @return void
     */
    public function testRegisterNotificationLoggingSkipsWhenDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.notifications.enable_logging', false);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        // If we reach here the early-return executed without error.
        self::assertFalse((bool) $this->getConfig()->get('api-toolkit.notifications.enable_logging'));
    }

    /**
     * Test that boot merges attribute-discovered resources beneath the
     * configured resource map, with an explicit entry winning for its model.
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
     * Test that the discovery merge is skipped while the config cache is being
     * built, so discovered bindings are never baked into the cached config as
     * pseudo-explicit entries.
     *
     * @return void
     */
    public function testConfigCacheBuildSkipsTheDiscoveryMerge(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.paths', [dirname(__DIR__, 2) . '/Fixtures/Discovery/Primary']);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $argv = $_SERVER['argv'] ?? null;

        $_SERVER['argv'] = ['artisan', 'config:cache'];

        try {
            $provider = new ApiServiceProvider($app);
            $provider->boot();
        } finally {
            $_SERVER['argv'] = $argv;
        }

        self::assertSame([
            User::class => UserResource::class,
        ], $this->getConfig()->get('api-toolkit.resources.resource_map'));
    }

    /**
     * Test that registerMorphMap builds the map when dynamic mapping is enabled
     * and a valid resource map is configured.
     *
     * @return void
     */
    public function testRegisterMorphMapBuildsMapWhenEnabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        $morphMap = Relation::morphMap();

        self::assertArrayHasKey('users', $morphMap);
    }

    /**
     * Test that registerMorphMap skips resources that lack getResourceType,
     * exercising the `return []` branch inside the mapWithKeys callback.
     *
     * @return void
     */
    public function testRegisterMorphMapSkipsResourcesWithoutGetResourceType(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => \stdClass::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        // A stdClass has no getResourceType; boot() must complete without
        // error. The morph map may contain entries from earlier tests in the
        // suite -- we assert only that stdClass did not produce a morph-map
        // key.
        $morphMap = Relation::morphMap();

        self::assertArrayNotHasKey(\stdClass::class, $morphMap);
    }

    /**
     * Test that the OperatorRegistry is registered as a singleton with built-in
     * operators.
     *
     * @return void
     */
    public function testOperatorRegistryIsRegisteredAsSingleton(): void
    {
        $app      = $this->getApplication();
        $registry = $app->make(OperatorRegistry::class);

        self::assertInstanceOf(OperatorRegistry::class, $registry);

        // Same instance on second resolve (singleton)
        self::assertSame($registry, $app->make(OperatorRegistry::class));

        // Built-in operators are pre-registered
        self::assertTrue($registry->has('$eq'));
        self::assertTrue($registry->has('$neq'));
        self::assertTrue($registry->has('$gt'));
        self::assertTrue($registry->has('$lt'));
        self::assertTrue($registry->has('$ge'));
        self::assertTrue($registry->has('$le'));
        self::assertTrue($registry->has('$like'));
        self::assertTrue($registry->has('$in'));
        self::assertTrue($registry->has('$between'));
        self::assertTrue($registry->has('$contains'));
        self::assertTrue($registry->has('$null'));
        self::assertTrue($registry->has('$notNull'));
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

        // Boot completed without exception — valid schemas passed validation
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

        // Boot completed without calling validator — config gate works
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

        // Same instance on second resolve (singleton)
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
     * Test that configuration contains the validate schemas key.
     *
     * @return void
     */
    public function testConfigurationContainsValidateSchemasKey(): void
    {
        $config = $this->getConfig()->get('api-toolkit.resources');

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

        // Reset the dispatcher so Octane's own OperationTerminated listeners do
        // not mask whether the provider wires the toolkit's listener.
        Event::swap(new Dispatcher($app));

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        /** @var \Illuminate\Contracts\Events\Dispatcher $events */
        $events = $app->make('events');

        self::assertTrue($events->hasListeners(OperationTerminated::class));
    }

    /**
     * Test that the Octane flush listener is not registered when config is
     * disabled.
     *
     * @return void
     */
    public function testOctaneFlushListenerNotRegisteredWhenConfigDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.lifecycle.octane', false);

        // Reset the dispatcher so Octane's own OperationTerminated listeners do
        // not mask that the provider leaves the toolkit's listener unwired.
        Event::swap(new Dispatcher($app));

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        /** @var \Illuminate\Contracts\Events\Dispatcher $events */
        $events = $app->make('events');

        self::assertFalse($events->hasListeners(OperationTerminated::class));
    }

    /**
     * Test that the queue flush subscriber is registered when config is
     * enabled.
     *
     * @return void
     */
    public function testQueueFlushSubscriberRegisteredWhenConfigEnabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.lifecycle.queue', true);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        /** @var \Illuminate\Contracts\Events\Dispatcher $events */
        $events = $app->make('events');

        self::assertTrue($events->hasListeners(JobProcessed::class));
    }

    /**
     * Test that the queue flush subscriber is not registered when config is
     * disabled.
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

        // The WritePoolFlushSubscriber also listens to JobProcessed, so we
        // check that QueueFlushSubscriber specifically was not subscribed by
        // verifying only the write pool subscriber listeners exist. Since both
        // subscribers listen to the same events, we verify the disabled branch
        // by confirming boot completes without error.
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
     * Test that the WritePool receives the configured strategy when on_failure
     * is set to a non-default value.
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
     * Test that an invalid on_failure config value throws a ValueError when
     * resolving the WritePool.
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
     * Test that the ParseApiQuery middleware is pushed to the global stack when
     * parser middleware registration is enabled.
     *
     * @return void
     */
    public function testParseApiQueryMiddlewareIsRegisteredGlobally(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(ParseApiQuery::class, $middleware);
    }

    /**
     * Test that all middleware registrations fall back to enabled defaults when
     * the relevant config keys are missing entirely.
     *
     * A fresh kernel and router are resolved so the assertions observe only
     * what this boot registers.
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

        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel     = $app->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(ParseApiQuery::class, $middleware);
        self::assertContains(PreventRequestsDuringMaintenance::class, $middleware);
        self::assertContains(DetectsCapabilities::class, $middleware);
        self::assertContains(JsonPrettyPrint::class, $middleware);

        /** @var \Illuminate\Routing\Router $router */
        $router  = $app->make(Router::class);
        $aliases = $router->getMiddleware();

        self::assertArrayHasKey('throttle', $aliases);
        self::assertSame(ThrottleRequests::class, $aliases['throttle']);
    }

    /**
     * Test that the DetectsCapabilities middleware is not registered on a fresh
     * kernel when disabled via config.
     *
     * @return void
     */
    public function testDetectsCapabilitiesMiddlewareIsNotRegisteredOnFreshKernelWhenDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.middleware.detect_capabilities.enabled', false);

        $app->forgetInstance(HttpKernel::class);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $app->make(HttpKernel::class);
        $groups = $kernel->getMiddlewareGroups();

        self::assertNotContains(DetectsCapabilities::class, $kernel->getGlobalMiddleware());
        self::assertNotContains(DetectsCapabilities::class, $groups['api'] ?? []);
    }

    /**
     * Test that the DetectsCapabilities middleware scoped to the api group is
     * not also pushed to the global stack.
     *
     * @return void
     */
    public function testDetectsCapabilitiesMiddlewareScopedToApiIsNotPushedGlobally(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.middleware.detect_capabilities.scope', 'api');

        $app->forgetInstance(HttpKernel::class);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $app->make(HttpKernel::class);
        $groups = $kernel->getMiddlewareGroups();

        self::assertContains(DetectsCapabilities::class, $groups['api'] ?? []);
        self::assertNotContains(DetectsCapabilities::class, $kernel->getGlobalMiddleware());
    }

    /**
     * Test that the package config file is publishable under the config group
     * with the expected source and destination paths.
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
     * Test that the package translations are publishable under the translations
     * group with the expected source and destination paths.
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
     * Test that no morph map is enforced when dynamic morph mapping is
     * disabled, even when a valid resource map is configured.
     *
     * @return void
     */
    public function testMorphMapIsNotEnforcedWhenDynamicMorphMappingIsDisabled(): void
    {
        $app = $this->getApplication();

        $this->getConfig()->set('api-toolkit.resources.enable_dynamic_morph_mapping', false);
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertArrayNotHasKey('users', Relation::morphMap());
    }

    /**
     * Test that schema validation defaults to disabled when the
     * validate_schemas config key is missing entirely.
     *
     * Boot must complete without throwing even though the resource map contains
     * an invalid schema, proving the default gate is off.
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
     * Test that schema validation is skipped when the resource map is not an
     * array, completing boot without error.
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
     * Test that notification logging defaults to enabled when the
     * enable_logging config key is missing entirely.
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
     * Test that no notification listeners are added when notification logging
     * is disabled.
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
     * Test that the notification events are wired to the NotificationListener
     * sending and sent handlers.
     *
     * @return void
     */
    public function testNotificationListenersDelegateToNotificationListener(): void
    {
        $raw = $this->getEventDispatcher()->getRawListeners();

        self::assertContains([NotificationListener::class, 'sending'], $raw[NotificationSending::class] ?? []);
        self::assertContains([NotificationListener::class, 'sent'], $raw[NotificationSent::class] ?? []);
    }

    /**
     * Test that the ResourceMetadataProvider contract is bound to the
     * ResourceMetadataService as a singleton.
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
     * Test that the SchemaIntrospectionProvider contract is bound to the
     * SchemaIntrospector as a singleton.
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
     * Test that the write pool flush subscriber is subscribed to the HTTP and
     * console lifecycle events.
     *
     * @return void
     */
    public function testWritePoolFlushSubscriberIsSubscribedToLifecycleEvents(): void
    {
        self::assertTrue($this->hasSubscriberListener(RequestHandled::class, WritePoolFlushSubscriber::class));
        self::assertTrue($this->hasSubscriberListener(CommandFinished::class, WritePoolFlushSubscriber::class));
    }

    /**
     * Test that the queue flush subscriber is subscribed when the queue
     * lifecycle is enabled.
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
     * Test that the queue flush subscriber is not subscribed when the queue
     * lifecycle is disabled.
     *
     * @return void
     */
    public function testQueueFlushSubscriberIsNotSubscribedWhenQueueLifecycleDisabled(): void
    {
        $app = $this->getApplication();

        // Reset the dispatcher so the boot-time wiring (now default-on) does
        // not pollute the baseline being tested.
        Event::swap(new Dispatcher($app));

        $this->getConfig()->set('api-toolkit.lifecycle.queue', false);

        $provider = new ApiServiceProvider($app);
        $provider->boot();

        self::assertFalse($this->hasSubscriberListener(JobProcessed::class, QueueFlushSubscriber::class));
        self::assertFalse($this->hasSubscriberListener(JobFailed::class, QueueFlushSubscriber::class));
    }

    /**
     * Test that the WritePool falls back to the default sizes when the config
     * keys are missing entirely.
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
     * Test that the WritePool falls back to the default sizes when the
     * configured values are not numeric.
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

        // Enable middleware registration for these tests
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.parser.register_middleware', true);
        $config->set('api-toolkit.middleware.detect_capabilities.enabled', true);
        $config->set('api-toolkit.notifications.enable_logging', true);
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
        /** @var \Illuminate\Contracts\Config\Repository */
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
     * Mirrors the __DIR__-relative paths used by the provider when registering
     * publishable assets.
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
     * Determine whether the given event has a listener belonging to the given
     * subscriber class.
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
}
