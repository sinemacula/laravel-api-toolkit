<?php

namespace Tests\Integration;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequestsWithRedis;
use Tests\TestCase;

/**
 * Integration tests for the throttle middleware with Redis cache driver.
 *
 * This test class configures the cache driver to redis before the service
 * provider boots, verifying the Redis throttle variant is auto-selected.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiServiceProvider::class)]
class MiddlewareConfigRedisThrottleTest extends TestCase
{
    /**
     * Test that the throttle middleware uses the Redis variant when the cache
     * driver is redis.
     *
     * @return void
     */
    public function testThrottleMiddlewareUsesRedisVariantWhenCacheIsRedis(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router     = $this->getApplication()->make(Router::class);
        $middleware = $router->getMiddleware();

        static::assertArrayHasKey('throttle', $middleware);
        static::assertSame(ThrottleRequestsWithRedis::class, $middleware['throttle']);
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

        $config->set('cache.default', 'redis');
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
