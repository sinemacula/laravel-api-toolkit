<?php

namespace SineMacula\ApiToolkit\RouteLinting\Dto;

/**
 * Inbound description of one route from the route-source adapter.
 *
 * Carries the raw, pre-normalisation route data handed in by the route-source
 * adapter: the URI as registered, the set of uppercase HTTP methods, the
 * optional route name, a flag indicating whether the route belongs to a vendor
 * package, and any inline suppressions declared via the `ignoreRouteLint`
 * macro. The domain never sees an Illuminate type; all framework details are
 * translated into this plain carrier at the adapter boundary.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RouteDescriptor
{
    /**
     * Create a new route descriptor.
     *
     * @param  string  $uri
     * @param  array<int, string>  $methods
     * @param  string|null  $name
     * @param  bool  $isVendor
     * @param  list<\SineMacula\ApiToolkit\RouteLinting\Dto\RouteSuppression>  $suppressions
     */
    public function __construct(

        /** The route URI as registered, e.g. `users/{user}` */
        public string $uri,

        /** The uppercase HTTP methods the route responds to, e.g. `['GET', 'HEAD']` */
        public array $methods,

        /** The route name, or null when the route is unnamed */
        public ?string $name,

        /** Whether the route belongs to a vendor package */
        public bool $isVendor,

        /** Inline suppressions declared via `ignoreRouteLint`; empty when none were registered */
        public array $suppressions = [],

    ) {}
}
