<?php

namespace Tests\Integration;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiServiceProvider;
use Tests\TestCase;

/**
 * Integration tests for the throttle middleware with a custom class override.
 *
 * This test class configures a custom throttle middleware class before the
 * service provider boots, verifying the custom class is used for the
 * throttle alias.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiServiceProvider::class)]
class MiddlewareConfigCustomThrottleTest extends TestCase
{
    /** @var string */
    private const CUSTOM_THROTTLE_CLASS = 'App\Http\Middleware\CustomThrottle';

    /**
     * Test that a custom throttle middleware class is used when configured.
     *
     * @return void
     */
    public function testThrottleMiddlewareUsesCustomClassWhenConfigured(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router     = $this->getApplication()->make(Router::class);
        $middleware = $router->getMiddleware();

        static::assertArrayHasKey('throttle', $middleware);
        static::assertSame(self::CUSTOM_THROTTLE_CLASS, $middleware['throttle']);
    }

    /**
     * Skip database migrations as these tests do not require a database.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        // No database needed for middleware registration tests.
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

        $config->set('api-toolkit.middleware.throttle.enabled', true);
        $config->set('api-toolkit.middleware.throttle.class', self::CUSTOM_THROTTLE_CLASS);
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
