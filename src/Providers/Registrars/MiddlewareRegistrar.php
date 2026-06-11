<?php

namespace SineMacula\ApiToolkit\Providers\Registrars;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Http\Middleware\DetectsCapabilities;
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
        $this->registerDetectsCapabilitiesMiddleware($kernel);
        $this->registerJsonPrettyPrintMiddleware($kernel);
        $this->registerThrottleMiddleware($router);
    }

    /**
     * Register the maintenance mode middleware swap.
     *
     * When enabled, the toolkit's PreventRequestsDuringMaintenance middleware
     * is prepended to the global middleware stack. Since it extends Laravel's
     * built-in middleware, it takes precedence without requiring removal of
     * the original.
     *
     * @param  \Illuminate\Foundation\Http\Kernel  $kernel
     * @return void
     */
    private function registerMaintenanceModeMiddleware(HttpKernel $kernel): void
    {
        if (!Config::get('api-toolkit.middleware.maintenance_mode_swap.enabled', true)) {
            return;
        }

        $kernel->prependMiddleware(PreventRequestsDuringMaintenance::class);
    }

    /**
     * Register the DetectsCapabilities middleware.
     *
     * When scope is 'global', the middleware is pushed to the global stack.
     * When scope is 'api', it is appended to the 'api' middleware group.
     * Capabilities still resolve lazily on first access when the middleware
     * is not registered; registration simply precomputes them per request.
     *
     * @param  \Illuminate\Foundation\Http\Kernel  $kernel
     * @return void
     */
    private function registerDetectsCapabilitiesMiddleware(HttpKernel $kernel): void
    {
        if (!Config::get('api-toolkit.middleware.detect_capabilities.enabled', true)) {
            return;
        }

        $scope = Config::get('api-toolkit.middleware.detect_capabilities.scope', 'global');

        if ($scope === 'api') {
            $kernel->appendMiddlewareToGroup('api', DetectsCapabilities::class);

            return;
        }

        $kernel->pushMiddleware(DetectsCapabilities::class);
    }

    /**
     * Register the JsonPrettyPrint middleware.
     *
     * When scope is 'global', the middleware is pushed to the global stack.
     * When scope is 'api', it is appended to the 'api' middleware group.
     *
     * @param  \Illuminate\Foundation\Http\Kernel  $kernel
     * @return void
     */
    private function registerJsonPrettyPrintMiddleware(HttpKernel $kernel): void
    {
        if (!Config::get('api-toolkit.middleware.json_pretty_print.enabled', true)) {
            return;
        }

        $scope = Config::get('api-toolkit.middleware.json_pretty_print.scope', 'global');

        if ($scope === 'api') {
            $kernel->appendMiddlewareToGroup('api', JsonPrettyPrint::class);

            return;
        }

        $kernel->pushMiddleware(JsonPrettyPrint::class);
    }

    /**
     * Register the throttle middleware alias.
     *
     * When a custom class is provided via config, that class is used.
     * Otherwise, the toolkit auto-detects between standard and Redis
     * variants based on the configured cache driver.
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
        /** @var class-string|null $custom_class */
        $custom_class = Config::get('api-toolkit.middleware.throttle.class');

        if ($custom_class !== null) {
            return $custom_class;
        }

        return Config::get('cache.default') !== 'redis'
            ? ThrottleRequests::class
            : ThrottleRequestsWithRedis::class;
    }
}
