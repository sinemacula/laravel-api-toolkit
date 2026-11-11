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
 * Also pins how the maintenance swap rewrites a seeded global stack, which the
 * provider suite cannot build.
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
     * Test that the registrar pushes kernel middleware and aliases throttle.
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

        // Without the framework middleware the toolkit's except list is final
        self::assertNotContains(FrameworkMaintenance::class, $middleware);

        /** @var \Illuminate\Routing\Router $router */
        $router = $app->make(Router::class);

        self::assertArrayHasKey('throttle', $router->getMiddleware());
    }

    /**
     * Test that the maintenance swap replaces the framework middleware in situ.
     *
     * @return void
     */
    public function testMaintenanceSwapReplacesFrameworkMiddlewareInPlace(): void
    {
        $middleware = $this->registerOver([TrustProxies::class, HandleCors::class, FrameworkMaintenance::class, ValidatePostSize::class]);

        self::assertSame([TrustProxies::class, HandleCors::class, PreventRequestsDuringMaintenance::class, ValidatePostSize::class], array_slice($middleware, 0, 4));
    }

    /**
     * Test that the maintenance swap does not duplicate the toolkit middleware.
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
     * Test that the maintenance swap adds nothing if the framework's is absent.
     *
     * An application that removed it has opted out.
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
     * Test that the maintenance swap leaves a toolkit registration where it is.
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
     * Seed the kernel's global stack, run the registrar, and return the stack.
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
