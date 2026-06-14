<?php

namespace SineMacula\ApiToolkit\RouteLinting;

/**
 * Pure value object representing a route under inspection.
 *
 * Carries the normalised properties extracted from a raw route descriptor and
 * exposes a stable identity key used for deterministic ordering and reporting.
 * All properties are derived from the route as registered; no framework types
 * cross the domain boundary.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class NormalisedRoute
{
    /**
     * Create a new normalised route.
     *
     * @param  string  $uri
     * @param  array<int, string>  $methods
     * @param  string|null  $name
     * @param  array<int, string>  $segments
     * @param  array<int, string>  $parameters
     */
    public function __construct(

        /** The route URI as registered, e.g. `users/{user}` (no leading slash assumed; stored as given) */
        public string $uri,

        /** Uppercase HTTP methods for this route, e.g. `['GET', 'HEAD']` */
        public array $methods,

        /** The route name, or null when the route is unnamed */
        public ?string $name,

        /** URI split on `/`, with empty segments preserved so trailing/duplicate slashes remain detectable */
        public array $segments,

        /** Route parameter names without braces, e.g. `['user']` */
        public array $parameters,

    ) {}

    /**
     * Return a stable identity key for deterministic ordering and reporting.
     *
     * The key is: HTTP methods sorted ascending joined by `,`, then a space,
     * then the URI, then (when the route has a name) a space and the name.
     * Example: methods `['HEAD','GET']`, uri `users`, name `users.index`
     * yields `GET,HEAD users users.index`.
     *
     * @return string
     */
    public function identity(): string
    {
        $sortedMethods = $this->methods;
        sort($sortedMethods);

        $key = implode(',', $sortedMethods) . ' ' . $this->uri;

        if ($this->name !== null) {
            $key .= ' ' . $this->name;
        }

        return $key;
    }
}
