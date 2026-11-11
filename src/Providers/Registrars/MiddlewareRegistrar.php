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
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class MiddlewareRegistrar
{
    /**
     * Create a new middleware registrar instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function __construct(

        /** The service container for resolving the router and kernel */
        private Container $container,
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
     * The swap keeps the framework middleware's position after HandleCors, so
     * the 503 still carries CORS headers. Nothing is added if it was removed.
     *
     * @param  \Illuminate\Foundation\Http\Kernel  $kernel
     * @return void
     */
    private function registerMaintenanceModeMiddleware(HttpKernel $kernel): void
    {
        if (!Config::get('api-toolkit.middleware.maintenance_mode_swap.enabled', true)) {
            return;
        }

        $kernel->setGlobalMiddleware(array_values(array_unique(array_map(
            static fn (string $middleware): string => $middleware === FrameworkMaintenance::class
                ? PreventRequestsDuringMaintenance::class
                : $middleware,
            $kernel->getGlobalMiddleware(),
        ))));
    }

    /**
     * Register a middleware globally or on the api group, as configured.
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
     * Uses the Redis variant when the default cache store is redis.
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
