<?php

namespace SineMacula\ApiToolkit\RouteLinting\Contracts;

/**
 * Outbound port for enumerating the application's owned routes.
 *
 * Implementers enumerate every app-owned route after a full boot, excluding
 * vendor routes the application cannot change. The domain never reads the
 * framework router directly — all route sourcing flows through this port.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface RouteSource
{
    /**
     * Enumerate every app-owned route, excluding vendor routes the app cannot change.
     *
     * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Dto\RouteDescriptor>
     */
    public function appRoutes(): array;
}
