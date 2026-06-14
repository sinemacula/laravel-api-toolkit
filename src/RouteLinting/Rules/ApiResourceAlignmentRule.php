<?php

namespace SineMacula\ApiToolkit\RouteLinting\Rules;

use SineMacula\ApiToolkit\RouteLinting\Contracts\Rule;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use SineMacula\ApiToolkit\RouteLinting\Violation;

/**
 * Rule R9: apiResource-alignment warning.
 *
 * Flags HTML-only form actions (`create` and `edit`) that appear as the final
 * literal segment of a URI on an API surface. These segments correspond to
 * Laravel's `create` and `edit` resource actions, which render HTML forms and
 * have no valid place in a JSON API. The check is restricted to the final
 * literal segment to keep precision high and avoid false positives on resource
 * names that happen to contain these strings in a non-terminal position.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ApiResourceAlignmentRule implements Rule
{
    /** HTML-only action segments that must not appear as the final literal URI segment on an API surface. */
    private const HTML_ONLY_ACTIONS = ['create', 'edit'];

    /**
     * Return the stable rule identifier.
     *
     * @return string
     */
    #[\Override]
    public function id(): string
    {
        return 'R9';
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
        $lastLiteral = $this->lastLiteralSegment($route->segments);

        if ($lastLiteral === null || !in_array($lastLiteral, self::HTML_ONLY_ACTIONS, true)) {
            return [];
        }

        return [
            new Violation(
                ruleId: $this->id(),
                severity: $this->severity(),
                routeIdentity: $route->identity(),
                offendingSurface: $lastLiteral,
                remediationHint: null,
            ),
        ];
    }

    /**
     * Return the final non-empty, non-parameter segment, or null when none exists.
     *
     * @param  array<int, string>  $segments
     * @return string|null
     */
    private function lastLiteralSegment(array $segments): ?string
    {
        foreach (array_reverse($segments) as $segment) {
            if ($segment === '' || str_starts_with($segment, '{')) {
                continue;
            }

            return $segment;
        }

        return null;
    }
}
