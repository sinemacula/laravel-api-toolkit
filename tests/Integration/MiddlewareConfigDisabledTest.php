<?php

namespace Tests\Integration;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequestsWithRedis;
use Tests\TestCase;

/**
 * Integration tests for the ApiServiceProvider with all middleware disabled.
 *
 * This test class configures the environment to disable all three middleware
 * registrations before the service provider boots, ensuring the disabled
 * code paths are exercised.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiServiceProvider::class)]
class MiddlewareConfigDisabledTest extends TestCase
{
    /**
     * Test that the maintenance mode middleware swap is skipped when disabled.
     *
     * @return void
     */
    public function testMaintenanceModeMiddlewareIsNotRegisteredWhenDisabled(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        static::assertNotContains(PreventRequestsDuringMaintenance::class, $middleware);
    }

    /**
     * Test that JsonPrettyPrint is not registered when disabled.
     *
     * @return void
     */
    public function testJsonPrettyPrintMiddlewareIsNotRegisteredWhenDisabled(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        static::assertNotContains(JsonPrettyPrint::class, $middleware);
    }

    /**
     * Test that the throttle alias is not overridden when disabled.
     *
     * @return void
     */
    public function testThrottleMiddlewareAliasIsNotOverriddenWhenDisabled(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router     = $this->getApplication()->make(Router::class);
        $middleware = $router->getMiddleware();

        // The throttle alias may exist from Laravel's defaults, but it should
        // not point to any of the toolkit's middleware classes
        if (isset($middleware['throttle'])) {
            static::assertNotSame(ThrottleRequests::class, $middleware['throttle']);
            static::assertNotSame(ThrottleRequestsWithRedis::class, $middleware['throttle']);
        } else {
            static::assertArrayNotHasKey('throttle', $middleware);
        }
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

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.middleware.maintenance_mode_swap.enabled', false);
        $config->set('api-toolkit.middleware.json_pretty_print.enabled', false);
        $config->set('api-toolkit.middleware.throttle.enabled', false);
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
}
