<?php

namespace SineMacula\ApiToolkit\RouteLinting;

use SineMacula\ApiToolkit\RouteLinting\Contracts\RouteSource;
use SineMacula\ApiToolkit\RouteLinting\Contracts\RuleConfiguration;
use SineMacula\ApiToolkit\RouteLinting\Dto\RouteDescriptor;

/**
 * Application use case that composes the route-linting ports and runs the engine.
 *
 * Sources app-owned routes via the RouteSource port, loads rule configuration
 * via the RuleConfiguration port, normalises each descriptor into a NormalisedRoute,
 * runs the RouteLintEngine over it, suppresses exempt violations via the
 * ExemptionAllowlist, and returns a populated RouteLintReport. Stale allowlist
 * entries — entries that matched no live route — are recorded on the report.
 *
 * This class carries no framework dependency; all I/O is mediated through the
 * injected ports. Determinism (NFR-01) is achieved by sorting descriptors by a
 * stable identity key before inspection so two runs over the same route table
 * and configuration produce byte-identical verdicts.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LintRoutes
{
    /**
     * Create a new lint-routes use case.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\Contracts\RouteSource  $routeSource
     * @param  \SineMacula\ApiToolkit\RouteLinting\Contracts\RuleConfiguration  $configuration
     * @param  \SineMacula\ApiToolkit\RouteLinting\RouteLintEngine  $engine
     */
    public function __construct(
        private readonly RouteSource $routeSource,
        private readonly RuleConfiguration $configuration,
        private readonly RouteLintEngine $engine,
    ) {}

    /**
     * Run the linter over every app-owned route and return the verdict.
     *
     * Steps:
     * 1. Load the RuleConfig (may throw StaleWaiverException; propagates to caller).
     * 2. Build the ExemptionAllowlist from config exemptions.
     * 3. Source app-owned RouteDescriptors.
     * 4. Sort descriptors deterministically by stable identity key (NFR-01).
     * 5. For each descriptor: normalise, run the engine, suppress exempt violations.
     * 6. Record unmatched allowlist entries as stale waivers on the report.
     *
     * @return \SineMacula\ApiToolkit\RouteLinting\RouteLintReport
     *
     * @throws \SineMacula\ApiToolkit\RouteLinting\Exceptions\StaleWaiverException
     */
    public function lint(): RouteLintReport
    {
        $config    = $this->configuration->load();
        $allowlist = new ExemptionAllowlist($config->exemptions);
        $report    = new RouteLintReport;

        $descriptors = $this->routeSource->appRoutes();
        $descriptors = $this->sortDescriptors($descriptors);

        foreach ($descriptors as $descriptor) {
            $normalised = $this->normalise($descriptor);
            $violations = $this->engine->inspect($normalised, $config);
            $exempt     = $allowlist->isExempt($descriptor->name, $descriptor->uri);

            if ($exempt) {
                continue;
            }

            foreach ($violations as $violation) {
                $report->addViolation($violation);
            }
        }

        foreach ($allowlist->unmatched() as $key) {
            $report->addStaleWaiver($key);
        }

        return $report;
    }

    /**
     * Sort a list of route descriptors by their stable identity key.
     *
     * The identity key is: sorted HTTP methods joined by comma, space, the URI,
     * and (when named) space and the name — matching NormalisedRoute::identity().
     * Sorting here before normalisation ensures the traversal order is stable
     * regardless of the router's enumeration order (NFR-01).
     *
     * @param  array<int, \SineMacula\ApiToolkit\RouteLinting\Dto\RouteDescriptor>  $descriptors
     * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Dto\RouteDescriptor>
     */
    private function sortDescriptors(array $descriptors): array
    {
        usort($descriptors, fn (RouteDescriptor $a, RouteDescriptor $b): int => $this->descriptorKey($a) <=> $this->descriptorKey($b));

        return $descriptors;
    }

    /**
     * Build the stable sort key for a RouteDescriptor.
     *
     * Mirrors the identity key produced by NormalisedRoute::identity() so
     * sorting before and after normalisation yields the same order.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RouteDescriptor  $descriptor
     * @return string
     */
    private function descriptorKey(RouteDescriptor $descriptor): string
    {
        $methods = $descriptor->methods;
        sort($methods);

        $key = implode(',', $methods) . ' ' . $descriptor->uri;

        if ($descriptor->name !== null) {
            $key .= ' ' . $descriptor->name;
        }

        return $key;
    }

    /**
     * Normalise a RouteDescriptor into a NormalisedRoute.
     *
     * Splits the URI on `/` (preserving empty segments for slash-sanity detection)
     * and extracts parameter names by stripping the `{` and `}` braces from any
     * segment that starts with `{`.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RouteDescriptor  $descriptor
     * @return \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute
     */
    private function normalise(RouteDescriptor $descriptor): NormalisedRoute
    {
        $segments   = $this->splitSegments($descriptor->uri);
        $parameters = $this->extractParameters($segments);

        return new NormalisedRoute(
            uri: $descriptor->uri,
            methods: $descriptor->methods,
            name: $descriptor->name,
            segments: $segments,
            parameters: $parameters,
        );
    }

    /**
     * Split a URI string into segments on the `/` delimiter.
     *
     * Empty segments are preserved so that trailing-slash and duplicate-slash
     * defects remain detectable by the SlashSanityRule.
     *
     * @param  string  $uri
     * @return array<int, string>
     */
    private function splitSegments(string $uri): array
    {
        return explode('/', $uri);
    }

    /**
     * Extract route parameter names from a list of URI segments.
     *
     * A segment is a route parameter when it starts with `{`. The surrounding
     * `{` and `}` braces are stripped from the returned names.
     *
     * @param  array<int, string>  $segments
     * @return array<int, string>
     */
    private function extractParameters(array $segments): array
    {
        $parameters = [];

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '{')) {
                $parameters[] = trim($segment, '{}');
            }
        }

        return $parameters;
    }
}
