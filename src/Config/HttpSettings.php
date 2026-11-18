<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Config;

use SineMacula\ApiToolkit\Enums\MiddlewareScope;
use SineMacula\ApiToolkit\Enums\RenderStrategy;

/**
 * The typed settings of the request, response and middleware layer.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class HttpSettings
{
    /**
     * Return when exceptions render as JSON.
     *
     * @return \SineMacula\ApiToolkit\Enums\RenderStrategy
     */
    public static function renderStrategy(): RenderStrategy
    {
        return ToolkitConfig::readEnum('exceptions.render_strategy', RenderStrategy::class);
    }

    /**
     * Determine whether error responses carry the underlying exception, or null
     * to follow app.debug.
     *
     * @return bool|null
     */
    public static function shouldIncludeDebugInfo(): ?bool
    {
        return ToolkitConfig::readNullableBoolean('exceptions.include_debug_info');
    }

    /**
     * Return the configured exception map, validated where it is built.
     *
     * @return array<array-key, mixed>
     */
    public static function exceptionMap(): array
    {
        return ToolkitConfig::readMap('exceptions.map');
    }

    /**
     * Return the container binding the query parser is resolved by.
     *
     * @return string
     */
    public static function parserAlias(): string
    {
        return ToolkitConfig::readString('parser.alias');
    }

    /**
     * Determine whether the query parsing middleware is registered.
     *
     * @return bool
     */
    public static function shouldRegisterParserMiddleware(): bool
    {
        return ToolkitConfig::readBoolean('parser.register_middleware');
    }

    /**
     * Return the header the correlation id is read from, or null for none.
     *
     * @return string|null
     */
    public static function correlationHeader(): ?string
    {
        return ToolkitConfig::readNullableString('services.correlation_header');
    }

    /**
     * Determine whether the toolkit's maintenance mode middleware replaces
     * Laravel's.
     *
     * @return bool
     */
    public static function shouldSwapMaintenanceMode(): bool
    {
        return ToolkitConfig::readBoolean('middleware.maintenance_mode_swap.enabled');
    }

    /**
     * Determine whether the pretty-print middleware is registered.
     *
     * @return bool
     */
    public static function shouldPrettyPrintJson(): bool
    {
        return ToolkitConfig::readBoolean('middleware.json_pretty_print.enabled');
    }

    /**
     * Return where the pretty-print middleware is registered.
     *
     * @return \SineMacula\ApiToolkit\Enums\MiddlewareScope
     */
    public static function jsonPrettyPrintScope(): MiddlewareScope
    {
        return ToolkitConfig::readEnum('middleware.json_pretty_print.scope', MiddlewareScope::class);
    }

    /**
     * Determine whether the throttle middleware alias is registered.
     *
     * @return bool
     */
    public static function shouldRegisterThrottle(): bool
    {
        return ToolkitConfig::readBoolean('middleware.throttle.enabled');
    }

    /**
     * Return the class replacing the toolkit's throttle middleware, if any.
     *
     * @return string|null
     */
    public static function throttleClass(): ?string
    {
        return ToolkitConfig::readNullableString('middleware.throttle.class');
    }

    /**
     * Return the URIs that stay reachable during maintenance mode.
     *
     * @return list<string>
     */
    public static function maintenanceExcept(): array
    {
        return ToolkitConfig::readList('maintenance_mode.except');
    }
}
