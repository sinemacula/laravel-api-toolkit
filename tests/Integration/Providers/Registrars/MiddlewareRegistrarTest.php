<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as FrameworkMaintenance;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Middleware\ValidatePostSize;
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
 * registers its surface when invoked directly, and pins how the maintenance
 * swap rewrites a seeded global stack, which the provider suite cannot build.
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

        /** @var \Illuminate\Config\Repository $config */
        $config = $app->make('config');

        $config->set('api-toolkit.parser.register_middleware', true);

        $app->forgetInstance(HttpKernel::class);
        $app->forgetInstance(Router::class);
        $app->forgetInstance('router');

        (new MiddlewareRegistrar($app))->register();

        /** @var \Orchestra\Testbench\Http\Kernel $kernel */
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
     * Test that the maintenance swap replaces the framework middleware in its
     * existing position rather than moving it ahead of HandleCors.
     *
     * @return void
     */
    public function testMaintenanceSwapReplacesFrameworkMiddlewareInPlace(): void
    {
        $middleware = $this->registerOver([TrustProxies::class, HandleCors::class, FrameworkMaintenance::class, ValidatePostSize::class]);

        self::assertSame([TrustProxies::class, HandleCors::class, PreventRequestsDuringMaintenance::class, ValidatePostSize::class], array_slice($middleware, 0, 4));
    }

    /**
     * Test that the maintenance swap does not duplicate the toolkit middleware
     * when it is already in the stack alongside the framework middleware.
     *
     * @return void
     */
    public function testMaintenanceSwapDoesNotDuplicateToolkitMiddleware(): void
    {
        $middleware = $this->registerOver([HandleCors::class, FrameworkMaintenance::class, PreventRequestsDuringMaintenance::class, ValidatePostSize::class]);

        self::assertSame([HandleCors::class, PreventRequestsDuringMaintenance::class, ValidatePostSize::class], array_slice($middleware, 0, 3));
        self::assertNotContains(FrameworkMaintenance::class, $middleware);
        self::assertCount(1, array_keys($middleware, PreventRequestsDuringMaintenance::class, true));
        self::assertTrue(array_is_list($middleware));
    }

    /**
     * Test that the maintenance swap adds nothing when the framework middleware
     * is not in the stack, since an application that removed it has opted out.
     *
     * @return void
     */
    public function testMaintenanceSwapAddsNothingWhenFrameworkMiddlewareIsAbsent(): void
    {
        $middleware = $this->registerOver([TrustProxies::class, HandleCors::class, ValidatePostSize::class]);

        self::assertSame([TrustProxies::class, HandleCors::class, ValidatePostSize::class], array_slice($middleware, 0, 3));
        self::assertNotContains(PreventRequestsDuringMaintenance::class, $middleware);
    }

    /**
     * Test that the maintenance swap leaves an existing toolkit registration
     * where it is when the framework middleware is not in the stack.
     *
     * @return void
     */
    public function testMaintenanceSwapLeavesExistingToolkitMiddlewareInPlace(): void
    {
        $middleware = $this->registerOver([TrustProxies::class, HandleCors::class, PreventRequestsDuringMaintenance::class]);

        self::assertSame([TrustProxies::class, HandleCors::class, PreventRequestsDuringMaintenance::class], array_slice($middleware, 0, 3));
        self::assertCount(1, array_keys($middleware, PreventRequestsDuringMaintenance::class, true));
    }

    /**
     * Seed the kernel's global stack, run the registrar over it, and return the
     * resulting global stack.
     *
     * @param  list<class-string>  $global
     * @return array<int, class-string|string>
     */
    private function registerOver(array $global): array
    {
        $app = $this->getApplication();

        /** @var \Orchestra\Testbench\Http\Kernel $kernel */
        $kernel = $app->make(HttpKernel::class);

        $kernel->setGlobalMiddleware($global);

        (new MiddlewareRegistrar($app))->register();

        return $kernel->getGlobalMiddleware();
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
