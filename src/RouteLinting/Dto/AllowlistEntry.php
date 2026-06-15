<?php

namespace SineMacula\ApiToolkit\RouteLinting\Dto;

/**
 * One exemption-allowlist entry.
 *
 * Carries the match key (a route name or URI pattern), the written reason
 * that justifies the waiver, and an optional list of rule IDs the entry
 * applies to. An empty `$rules` list means the waiver covers all rules.
 * Emptiness of `reason` is validated upstream by the config adapter; this DTO
 * stores all values exactly as given.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class AllowlistEntry
{
    /**
     * Create a new allowlist entry.
     *
     * @param  string  $match
     * @param  string  $reason
     * @param  list<string>  $rules
     */
    public function __construct(

        /** The route name or URI pattern to waive */
        public string $match,

        /** The required, non-empty written justification for the waiver */
        public string $reason,

        /** The rule IDs covered by this entry; empty means all rules */
        public array $rules = [],

    ) {}

    /**
     * Determine whether this allowlist entry covers the given rule.
     *
     * Returns true when the entry waives all rules (empty list) or when the
     * given rule ID is explicitly present in the rules list.
     *
     * @param  string  $ruleId
     * @return bool
     */
    public function covers(string $ruleId): bool
    {
        return $this->rules === [] || in_array($ruleId, $this->rules, true);
    }
}
