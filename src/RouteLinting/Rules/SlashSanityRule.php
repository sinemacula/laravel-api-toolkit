<?php

namespace SineMacula\ApiToolkit\RouteLinting\Rules;

use SineMacula\ApiToolkit\RouteLinting\Contracts\Rule;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use SineMacula\ApiToolkit\RouteLinting\Violation;

/**
 * Rule R5: Slash-sanity enforcement.
 *
 * Flags any route URI that contains a trailing slash or a duplicate (empty)
 * slash. One violation is emitted per offending route regardless of how many
 * defects are present. The root path and empty URIs are excluded from
 * inspection because they are not REST collection URIs.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class SlashSanityRule implements Rule
{
    /**
     * Return the stable rule identifier.
     *
     * @return string
     */
    #[\Override]
    public function id(): string
    {
        return 'R5';
    }

    /**
     * Return the severity tier for this rule.
     *
     * @return \SineMacula\ApiToolkit\RouteLinting\Severity
     */
    #[\Override]
    public function severity(): Severity
    {
        return Severity::ERROR;
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
        $uri = $route->uri;

        if ($uri === '' || $uri === '/') {
            return [];
        }

        $hasTrailingSlash  = str_ends_with($uri, '/');
        $hasDuplicateSlash = str_contains($uri, '//');

        if (!$hasTrailingSlash && !$hasDuplicateSlash) {
            return [];
        }

        return [
            new Violation(
                ruleId: $this->id(),
                severity: Severity::ERROR,
                routeIdentity: $route->identity(),
                offendingSurface: $uri,
                remediationHint: null,
            ),
        ];
    }
}
