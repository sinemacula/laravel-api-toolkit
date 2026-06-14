<?php

namespace SineMacula\ApiToolkit\RouteLinting;

use SineMacula\ApiToolkit\RouteLinting\Contracts\Rule;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;

/**
 * Pure ordered rule-set orchestrator for a single route.
 *
 * Runs each registered rule over a single normalised route and returns the
 * aggregated violations in a deterministic order. Rules are executed in the
 * fixed order they were supplied to the constructor — no sorting, no
 * randomness, no global state — so calling inspect() twice with the same
 * inputs returns byte-identical arrays (NFR-01).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RouteLintEngine
{
    /** @var array<int|string, \SineMacula\ApiToolkit\RouteLinting\Contracts\Rule> */
    private readonly array $rules;

    /**
     * Create a new route lint engine.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\Contracts\Rule  ...$rules
     */
    public function __construct(Rule ...$rules)
    {
        $this->rules = $rules;
    }

    /**
     * Run every rule over the given normalised route and return the aggregated violations.
     *
     * Rules are executed in the fixed order they were supplied to the constructor.
     * The returned array preserves that order — no additional sorting is applied
     * here; deterministic final ordering is the report's responsibility.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute  $route
     * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig  $config
     * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Violation>
     */
    public function inspect(NormalisedRoute $route, RuleConfig $config): array
    {
        $violations = [];

        foreach ($this->rules as $rule) {
            array_push($violations, ...$rule->inspect($route, $config));
        }

        return $violations;
    }
}
