<?php

namespace SineMacula\ApiToolkit\RouteLinting;

/**
 * Immutable finding emitted by a route-linting rule.
 *
 * Carries the rule that triggered the finding, its severity tier, the stable
 * identity of the offending route, the specific surface that violated the rule,
 * and an optional RESTful-rewrite hint where the rule can suggest one.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class Violation
{
    /**
     * Create a new violation.
     *
     * @param  string  $ruleId
     * @param  \SineMacula\ApiToolkit\RouteLinting\Severity  $severity
     * @param  string  $routeIdentity
     * @param  string  $offendingSurface
     * @param  string|null  $remediationHint
     */
    public function __construct(

        /** Stable rule identifier, e.g. `R1` */
        public string $ruleId,

        /** Severity tier: ERROR gates CI, WARNING is reported only */
        public Severity $severity,

        /** The `NormalisedRoute::identity()` of the offending route */
        public string $routeIdentity,

        /** The offending segment, route name, or method+URI shape */
        public string $offendingSurface,

        /** A RESTful-rewrite hint where the rule provides one, or null */
        public ?string $remediationHint,

    ) {}
}
