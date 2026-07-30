<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as FrameworkMaintenance;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use SineMacula\ApiToolkit\Providers\Registrars\MiddlewareRegistrar;
use Tests\TestCase;

/**
 * Integration tests for the MiddlewareRegistrar.
 *
 * The middleware configuration permutations are pinned by the
 * ApiServiceProvider integration suite; this test proves the registrar
 * registers its surface when invoked directly.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MiddlewareRegistrar::class)]
final class MiddlewareRegistrarTest extends TestCase
{
    /**
     * Test that the registrar pushes the kernel middleware and aliases the
     * throttle middleware when invoked.
     *
     * @return void
     */
    public function testRegisterBindsMiddlewareSurface(): void
    {
        $app = $this->getApplication();

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.parser.register_middleware', true);

        $app->forgetInstance(HttpKernel::class);
        $app->forgetInstance(Router::class);
        $app->forgetInstance('router');

        (new MiddlewareRegistrar($app))->register();

        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel     = $app->make(HttpKernel::class);
        $middleware = $kernel->getGlobalMiddleware();

        self::assertContains(ParseApiQuery::class, $middleware);
        self::assertContains(PreventRequestsDuringMaintenance::class, $middleware);
        self::assertContains(JsonPrettyPrint::class, $middleware);

        // The maintenance swap removes the framework middleware so the toolkit
        // middleware is the sole gate and its except list is authoritative.
        self::assertNotContains(FrameworkMaintenance::class, $middleware);

        /** @var \Illuminate\Routing\Router $router */
        $router = $app->make(Router::class);

        self::assertArrayHasKey('throttle', $router->getMiddleware());
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
