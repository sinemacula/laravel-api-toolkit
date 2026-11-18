<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as FrameworkMaintenance;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Config\HttpSettings;
use SineMacula\ApiToolkit\Enums\MiddlewareScope;
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

        if (HttpSettings::shouldRegisterParserMiddleware()) {
            $kernel->pushMiddleware(ParseApiQuery::class);
        }

        $this->registerMaintenanceModeMiddleware($kernel);
        $this->registerJsonPrettyPrintMiddleware($kernel);
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
        if (!HttpSettings::shouldSwapMaintenanceMode()) {
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
     * Register the pretty-print middleware globally or on the api group, as
     * configured.
     *
     * @param  \Illuminate\Foundation\Http\Kernel  $kernel
     * @return void
     */
    private function registerJsonPrettyPrintMiddleware(HttpKernel $kernel): void
    {
        if (!HttpSettings::shouldPrettyPrintJson()) {
            return;
        }

        if (HttpSettings::jsonPrettyPrintScope() === MiddlewareScope::API) {
            $kernel->appendMiddlewareToGroup('api', JsonPrettyPrint::class);

            return;
        }

        $kernel->pushMiddleware(JsonPrettyPrint::class);
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
        if (!HttpSettings::shouldRegisterThrottle()) {
            return;
        }

        $router->aliasMiddleware('throttle', $this->getThrottleMiddleware());
    }

    /**
     * Return the throttle middleware that should be used.
     *
     * @return string
     */
    private function getThrottleMiddleware(): string
    {
        $customClass = HttpSettings::throttleClass();

        if ($customClass !== null) {
            return $customClass;
        }

        return Config::get('cache.default') !== 'redis'
            ? ThrottleRequests::class
            : ThrottleRequestsWithRedis::class;
    }
}
