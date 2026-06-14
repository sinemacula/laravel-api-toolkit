<?php

namespace SineMacula\ApiToolkit\RouteLinting\Contracts;

use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use SineMacula\ApiToolkit\RouteLinting\Violation;

/**
 * Domain contract for a single route-linting rule.
 *
 * Each rule carries a stable identifier, a fixed severity tier, and an
 * inspection method that maps one normalised route plus the active rule
 * configuration to zero or more violation value objects. Rules are pure:
 * they read their inputs and return findings without side effects.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface Rule
{
    /**
     * Stable rule identifier, e.g. 'R1', used for ordering and reporting.
     *
     * @return string
     */
    public function id(): string;

    /**
     * The severity this rule emits (error gates CI; warning is reported only).
     *
     * @return \SineMacula\ApiToolkit\RouteLinting\Severity
     */
    public function severity(): Severity;

    /**
     * Inspect one normalised route and return zero or more violations.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute  $route
     * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig  $config
     * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Violation>
     */
    public function inspect(NormalisedRoute $route, RuleConfig $config): array;
}
