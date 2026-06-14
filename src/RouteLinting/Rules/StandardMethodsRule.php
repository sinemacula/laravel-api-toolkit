<?php

namespace SineMacula\ApiToolkit\RouteLinting\Rules;

use SineMacula\ApiToolkit\RouteLinting\Contracts\Rule;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use SineMacula\ApiToolkit\RouteLinting\Violation;

/**
 * Rule R7: Standard HTTP methods only.
 *
 * Flags any route that carries at least one HTTP method outside the standard
 * set (GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS). This catches routes
 * registered with Route::any() or a custom/non-standard verb such as PURGE.
 * An empty methods array produces no violation (defensive guard).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StandardMethodsRule implements Rule
{
    /** The RFC-standard HTTP methods that the linter accepts without complaint. */
    private const ALLOWED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * Return the stable rule identifier.
     *
     * @return string
     */
    #[\Override]
    public function id(): string
    {
        return 'R7';
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
        $nonStandard = array_values(array_filter(
            $route->methods,
            static fn (string $method): bool => !in_array($method, self::ALLOWED_METHODS, true),
        ));

        if ($nonStandard === []) {
            return [];
        }

        sort($nonStandard);

        return [
            new Violation(
                ruleId: $this->id(),
                severity: $this->severity(),
                routeIdentity: $route->identity(),
                offendingSurface: implode(', ', $nonStandard),
                remediationHint: null,
            ),
        ];
    }
}
