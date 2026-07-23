<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as FrameworkMaintenance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Middleware\PreventRequestsDuringMaintenance;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequestsWithRedis;

/**
 * Registers the toolkit middleware.
 *
 * Pushes the query parser, maintenance mode, capability detection, and JSON
 * pretty print middleware onto the HTTP kernel, and aliases the throttle
 * middleware on the router, honouring the configured gates and scopes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class MiddlewareRegistrar
{
    /**
     * Create a new middleware registrar instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function __construct(

        /** The service container for resolving the router and kernel. */
        private readonly Container $container,
    ) {}

    /**
     * Register any relevant middleware.
     *
     * @return void
     */
    public function register(): void
    {
        $router = $this->container->make(Router::class);

        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $this->container->make(Kernel::class);

        if (Config::get('api-toolkit.parser.register_middleware', true)) {
            $kernel->pushMiddleware(ParseApiQuery::class);
        }

        $this->registerMaintenanceModeMiddleware($kernel);
        $this->registerScopedMiddleware($kernel, 'api-toolkit.middleware.json_pretty_print', JsonPrettyPrint::class);
        $this->registerThrottleMiddleware($router);
    }

    /**
     * Register the maintenance mode middleware swap.
     *
     * When enabled, the framework's built-in maintenance middleware is removed
     * from the global stack and the toolkit's is prepended in its place. The
     * toolkit middleware extends and fully supersedes the original, so leaving
     * the original in place would re-throw a raw 503 for the paths the toolkit
     * bypasses, defeating the configured maintenance exceptions.
     *
     * @param  \Illuminate\Foundation\Http\Kernel  $kernel
     * @return void
     */
    private function registerMaintenanceModeMiddleware(HttpKernel $kernel): void
    {
        if (!Config::get('api-toolkit.middleware.maintenance_mode_swap.enabled', true)) {
            return;
        }

        $kernel->setGlobalMiddleware(array_filter(
            $kernel->getGlobalMiddleware(),
            static fn ($middleware): bool => $middleware !== FrameworkMaintenance::class,
        ));

        $kernel->prependMiddleware(PreventRequestsDuringMaintenance::class);
    }

    /**
     * Register a middleware honouring its enabled gate and configured scope.
     *
     * When scope is 'global', the middleware is pushed to the global stack.
     * When scope is 'api', it is appended to the 'api' middleware group.
     *
     * @param  \Illuminate\Foundation\Http\Kernel  $kernel
     * @param  string  $config
     * @param  class-string  $middleware
     * @return void
     */
    private function registerScopedMiddleware(HttpKernel $kernel, string $config, string $middleware): void
    {
        if (!Config::get($config . '.enabled', true)) {
            return;
        }

        $scope = Config::get($config . '.scope', 'global');

        if ($scope === 'api') {
            $kernel->appendMiddlewareToGroup('api', $middleware);

            return;
        }

        $kernel->pushMiddleware($middleware);
    }

    /**
     * Register the throttle middleware alias.
     *
     * When a custom class is provided via config, that class is used.
     * Otherwise, the toolkit auto-detects between standard and Redis variants
     * based on the configured cache driver.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    private function registerThrottleMiddleware(Router $router): void
    {
        if (!Config::get('api-toolkit.middleware.throttle.enabled', true)) {
            return;
        }

        $router->aliasMiddleware('throttle', $this->getThrottleMiddleware());
    }

    /**
     * Return the throttle middleware that should be used.
     *
     * @return class-string
     */
    private function getThrottleMiddleware(): string
    {
        /** @var class-string|null $customClass */
        $customClass = Config::get('api-toolkit.middleware.throttle.class');

        if ($customClass !== null) {
            return $customClass;
        }

        return Config::get('cache.default') !== 'redis'
            ? ThrottleRequests::class
            : ThrottleRequestsWithRedis::class;
    }
}
