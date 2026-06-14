<?php

namespace SineMacula\ApiToolkit\RouteLinting\Rules;

use SineMacula\ApiToolkit\RouteLinting\Contracts\Rule;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use SineMacula\ApiToolkit\RouteLinting\Violation;

/**
 * Rule R2: Kebab-case segment enforcement.
 *
 * Flags any literal URI segment that does not conform to kebab-case, i.e.
 * does not match the pattern `^[a-z0-9]+(-[a-z0-9]+)*$`. Route-parameter
 * segments (those wrapped in `{...}`) and empty segments are ignored.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class KebabCaseRule implements Rule
{
    /**
     * Return the stable rule identifier.
     *
     * @return string
     */
    #[\Override]
    public function id(): string
    {
        return 'R2';
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
        $violations = [];

        foreach ($route->segments as $segment) {
            if ($segment === '' || str_starts_with($segment, '{')) {
                continue;
            }

            if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $segment)) {
                $violations[] = new Violation(
                    ruleId: $this->id(),
                    severity: Severity::ERROR,
                    routeIdentity: $route->identity(),
                    offendingSurface: $segment,
                    remediationHint: null,
                );
            }
        }

        return $violations;
    }
}
