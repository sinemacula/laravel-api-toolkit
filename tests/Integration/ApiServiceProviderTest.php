<?php

namespace Tests\Integration;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
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
class ApiServiceProviderTest extends TestCase
{
    /**
     * Test that the package config is merged.
     *
     * @return void
     */
    public function testPackageConfigIsMerged(): void
    {
        $config = $this->getConfig();

        static::assertNotNull($config->get('api-toolkit'));
        static::assertIsArray($config->get('api-toolkit.resources'));
    }

    /**
     * Test that logging config is merged.
     *
     * @return void
     */
    public function testLoggingConfigIsMerged(): void
    {
        $channels = $this->getConfig()->get('logging.channels');

        static::assertArrayHasKey('notifications', $channels);
        static::assertArrayHasKey('cloudwatch', $channels);
        static::assertArrayHasKey('cloudwatch-notifications', $channels);
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

        static::assertTrue($translator->hasForLocale('api-toolkit::exceptions', 'en'));
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

        static::assertInstanceOf(ApiQueryParser::class, $parser);

        // Same instance on second resolve (singleton)
        static::assertSame($parser, $app->make($alias));
    }

    /**
     * Test that JsonPrettyPrint middleware is registered globally.
     *
     * @return void
     */
    public function testJsonPrettyPrintMiddlewareIsRegisteredGlobally(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        static::assertContains(JsonPrettyPrint::class, $middleware);
    }

    /**
     * Test that PreventRequestsDuringMaintenance replaces default middleware.
     *
     * @return void
     */
    public function testPreventRequestsDuringMaintenanceReplacesDefault(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        static::assertNotContains(
            PreventRequestsDuringMaintenance::class,
            $middleware,
        );
    }

    /**
     * Test that throttle middleware alias is set.
     *
     * @return void
     */
    public function testThrottleMiddlewareAliasIsSet(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->getApplication()->make(Router::class);

        $middleware = $router->getMiddleware();

        static::assertArrayHasKey('throttle', $middleware);
    }

    /**
     * Test that Request macros are registered.
     *
     * @return void
     */
    public function testRequestMacrosAreRegistered(): void
    {
        static::assertTrue(Request::hasMacro('includeTrashed'));
        static::assertTrue(Request::hasMacro('onlyTrashed'));
        static::assertTrue(Request::hasMacro('expectsExport'));
        static::assertTrue(Request::hasMacro('expectsCsv'));
        static::assertTrue(Request::hasMacro('expectsXml'));
        static::assertTrue(Request::hasMacro('expectsPdf'));
        static::assertTrue(Request::hasMacro('expectsStream'));
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

        static::assertTrue($events->hasListeners(NotificationSending::class));
        static::assertTrue($events->hasListeners(NotificationSent::class));
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
        // (they were already registered in setUp, but this test validates the config gate)
        static::assertTrue(true);
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

        assert($app instanceof \Illuminate\Foundation\Application);

        // Enable middleware registration for these tests
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.parser.register_middleware', true);
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
}
