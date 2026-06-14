<?php

namespace SineMacula\ApiToolkit\RouteLinting\Dto;

/**
 * One exemption-allowlist entry.
 *
 * Carries the match key (a route name or URI pattern) and the written reason
 * that justifies the waiver. Emptiness of `reason` is validated upstream by
 * the config adapter (task 12); this DTO stores both values exactly as given.
 * Task 04 builds the matching and stale-waiver detection logic on top of these
 * entries.
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
     */
    public function __construct(

        /** The route name or URI pattern to waive */
        public string $match,

        /** The required, non-empty written justification for the waiver */
        public string $reason,

    ) {}
}
