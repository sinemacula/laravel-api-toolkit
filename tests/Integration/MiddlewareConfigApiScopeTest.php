<?php

namespace Tests\Integration;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use Tests\TestCase;

/**
 * Integration tests for JsonPrettyPrint middleware with api scope.
 *
 * This test class configures the JsonPrettyPrint scope to 'api' before the
 * service provider boots, verifying it is appended to the api middleware
 * group instead of the global stack.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiServiceProvider::class)]
class MiddlewareConfigApiScopeTest extends TestCase
{
    /**
     * Test that JsonPrettyPrint is not in the global middleware stack.
     *
     * @return void
     */
    public function testJsonPrettyPrintIsNotInGlobalMiddleware(): void
    {
        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel     = $this->getApplication()->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        static::assertNotContains(JsonPrettyPrint::class, $middleware);
    }

    /**
     * Test that JsonPrettyPrint is appended to the api middleware group.
     *
     * @return void
     */
    public function testJsonPrettyPrintIsInApiMiddlewareGroup(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router    = $this->getApplication()->make(Router::class);
        $api_group = $router->getMiddlewareGroups()['api'] ?? [];

        static::assertContains(JsonPrettyPrint::class, $api_group);
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

        $config->set('api-toolkit.middleware.json_pretty_print.enabled', true);
        $config->set('api-toolkit.middleware.json_pretty_print.scope', 'api');
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
