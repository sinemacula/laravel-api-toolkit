<?php

namespace SineMacula\ApiToolkit\RouteLinting\Dto;

/**
 * Route-local suppression declared inline via the `ignoreRouteLint` macro.
 *
 * Carries a list of rule IDs to suppress and the written justification supplied
 * by the developer. An empty `$rules` list means "suppress all rules" for this
 * route. The `covers()` helper lets the engine query suppression membership
 * without exposing the internal representation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RouteSuppression
{
    /**
     * Create a new route suppression.
     *
     * @param  list<string>  $rules
     * @param  string  $reason
     */
    public function __construct(

        /** The rule IDs covered by this suppression; empty means all rules */
        public array $rules,

        /** The non-empty written justification for this suppression */
        public string $reason,

    ) {}

    /**
     * Determine whether this suppression covers the given rule.
     *
     * Returns true when the suppression targets all rules (empty list) or when
     * the given rule ID is explicitly present in the rules list.
     *
     * @param  string  $ruleId
     * @return bool
     */
    public function covers(string $ruleId): bool
    {
        return $this->rules === [] || in_array($ruleId, $this->rules, true);
    }
}
