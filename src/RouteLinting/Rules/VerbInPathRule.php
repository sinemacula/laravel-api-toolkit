<?php

namespace SineMacula\ApiToolkit\RouteLinting\Rules;

use SineMacula\ApiToolkit\RouteLinting\Contracts\Rule;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Rules\Support\SegmentNormaliser;
use SineMacula\ApiToolkit\RouteLinting\Rules\Support\VerbDenylist;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use SineMacula\ApiToolkit\RouteLinting\Violation;

/**
 * Error-severity rule R1: flags action verbs embedded in URI path segments.
 *
 * Reduces the route URI to candidate words via the SegmentNormaliser pipeline
 * (split, drop parameters, drop version/prefix, decompose compound, lowercase,
 * singularise), then tests each candidate against the VerbDenylist. Each
 * distinct denylisted verb found across the route's segments produces one
 * error-severity Violation carrying the verb as the offending surface and the
 * configured RESTful-rewrite hint. A clean plural collection such as `/users`
 * normalises to the noun `user` which is not a verb and is never flagged.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class VerbInPathRule implements Rule
{
    /**
     * Create a new verb-in-path rule.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\Rules\Support\SegmentNormaliser  $normaliser
     * @param  \SineMacula\ApiToolkit\RouteLinting\Rules\Support\VerbDenylist  $denylist
     */
    public function __construct(
        private readonly SegmentNormaliser $normaliser,
        private readonly VerbDenylist $denylist,
    ) {}

    /**
     * Return the stable rule identifier.
     *
     * @return string
     */
    #[\Override]
    public function id(): string
    {
        return 'R1';
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
     * Inspect the route for action verbs in path segments and return one violation per distinct offender.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute  $route
     * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig  $config
     * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Violation>
     */
    #[\Override]
    public function inspect(NormalisedRoute $route, RuleConfig $config): array
    {
        $words      = $this->normaliser->normalise($route->uri, $config->uncountables);
        $violations = [];
        $seen       = [];

        foreach ($words as $word) {
            if (isset($seen[$word]) || !$this->denylist->contains($word)) {
                continue;
            }

            $seen[$word]  = true;
            $violations[] = new Violation(
                ruleId: 'R1',
                severity: Severity::ERROR,
                routeIdentity: $route->identity(),
                offendingSurface: $word,
                remediationHint: $this->denylist->hint($word),
            );
        }

        return $violations;
    }
}
