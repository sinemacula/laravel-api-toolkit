<?php

namespace SineMacula\ApiToolkit\RouteLinting\Rules;

use SineMacula\ApiToolkit\RouteLinting\Contracts\Rule;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use SineMacula\ApiToolkit\RouteLinting\Violation;

/**
 * Rule R11: Nesting-depth smell warning.
 *
 * Flags routes whose URI nests more than three collection levels. A collection
 * level is each literal (non-parameter, non-empty) segment excluding common
 * API prefix segments (`api` and version tokens matching `v\d+`). Nesting
 * beyond three levels indicates a URI that could be restructured as a
 * top-level or shallower resource, reducing coupling between resource types.
 * A depth of exactly three is clean; four or more triggers a warning.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NestingDepthRule implements Rule
{
    /** Maximum collection levels before a warning is raised. */
    private const MAX_DEPTH = 3;

    /** Literal prefix segments excluded from the collection-level count. */
    private const EXCLUDED_PREFIXES = ['api'];

    /**
     * Return the stable rule identifier.
     *
     * @return string
     */
    #[\Override]
    public function id(): string
    {
        return 'R11';
    }

    /**
     * Return the severity tier for this rule.
     *
     * @return \SineMacula\ApiToolkit\RouteLinting\Severity
     */
    #[\Override]
    public function severity(): Severity
    {
        return Severity::WARNING;
    }

    /**
     * Inspect one normalised route and return zero or more violations.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute  $route
     * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig  $config
     * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Violation>
     */
    #[\Override]
    public function inspect(NormalisedRoute $route, RuleConfig $config): array
    {
        $depth = $this->collectionDepth($route->segments);

        if ($depth <= self::MAX_DEPTH) {
            return [];
        }

        return [
            new Violation(
                ruleId: $this->id(),
                severity: Severity::WARNING,
                routeIdentity: $route->identity(),
                offendingSurface: $route->uri,
                remediationHint: null,
            ),
        ];
    }

    /**
     * Count the number of collection-level literal segments in the given segment list.
     *
     * Empty segments, route-parameter segments, `api`, and version-prefix tokens
     * (`v` followed by one or more digits) are excluded from the count.
     *
     * @param  array<int, string>  $segments
     * @return int
     */
    private function collectionDepth(array $segments): int
    {
        $depth = 0;

        foreach ($segments as $segment) {
            if ($segment === '' || str_starts_with($segment, '{')) {
                continue;
            }

            if (in_array($segment, self::EXCLUDED_PREFIXES, true)) {
                continue;
            }

            if (preg_match('/^v\d+$/i', $segment)) {
                continue;
            }

            $depth++;
        }

        return $depth;
    }
}
