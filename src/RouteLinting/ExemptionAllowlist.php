<?php

namespace SineMacula\ApiToolkit\RouteLinting;

use SineMacula\ApiToolkit\RouteLinting\Dto\AllowlistEntry;

/**
 * Domain service that suppresses waived violations and tracks stale entries.
 *
 * Constructed from the exemption entries supplied by the rule-configuration
 * port, this service answers whether a given route carries a per-rule allowlist
 * suppression, and records which entries were matched so unmatched entries can
 * be reported as stale waivers after the full route table has been inspected.
 * An empty allowlist is the shipped default; in that state no route is suppressed
 * and no stale entries are ever reported.
 *
 * The two-phase protocol is:
 * 1. Call `observe()` once per live route so pattern-match tracking is driven
 *    by the full route table (not just routes that have violations).
 * 2. Call `suppresses()` per violation to test per-rule coverage and record usage.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExemptionAllowlist
{
    /** @var array<int, \SineMacula\ApiToolkit\RouteLinting\Dto\AllowlistEntry> */
    private readonly array $entries;

    /** @var array<int, bool> Index positions of entries whose pattern matched at least one live route */
    private array $matched = [];

    /** @var array<int, bool> Index positions of entries that suppressed at least one violation */
    private array $used = [];

    /**
     * Create a new exemption allowlist.
     *
     * @param  array<int, \SineMacula\ApiToolkit\RouteLinting\Dto\AllowlistEntry>  $entries
     */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    /**
     * Record that a live route has been observed.
     *
     * Marks every entry whose pattern matches this route as having been matched
     * by a live route. Call once per live route before calling `suppresses()`,
     * so that pattern-matching for stale detection covers the full route table
     * independently of whether violations are raised.
     *
     * @param  string|null  $routeName
     * @param  string  $uri
     * @return void
     */
    public function observe(?string $routeName, string $uri): void
    {
        foreach ($this->entries as $index => $entry) {
            if ($this->entryMatches($entry, $routeName, $uri)) {
                $this->matched[$index] = true;
            }
        }
    }

    /**
     * Determine whether any allowlist entry suppresses the given rule on this route.
     *
     * Returns true when at least one entry matches by name or URI pattern AND
     * covers the given rule ID (either the entry waives all rules, or the rule ID
     * is explicitly in the entry's rules list). Matching entries are also recorded
     * as having been matched by a live route (equivalent to `observe()`), and the
     * first suppressing entry is marked as having suppressed a violation.
     *
     * @param  string|null  $routeName
     * @param  string  $uri
     * @param  string  $ruleId
     * @return bool
     */
    public function suppresses(?string $routeName, string $uri, string $ruleId): bool
    {
        foreach ($this->entries as $index => $entry) {
            if (!$this->entryMatches($entry, $routeName, $uri)) {
                continue;
            }

            $this->matched[$index] = true;

            if ($entry->covers($ruleId)) {
                $this->used[$index] = true;

                return true;
            }
        }

        return false;
    }

    /**
     * Return the match keys of entries that never matched any live route during this run.
     *
     * Results are sorted ascending for determinism.
     *
     * @return array<int, string>
     */
    public function unmatched(): array
    {
        $stale = [];

        foreach ($this->entries as $index => $entry) {
            if (!($this->matched[$index] ?? false)) {
                $stale[] = $entry->match;
            }
        }

        sort($stale);

        return $stale;
    }

    /**
     * Return descriptive strings for entries that matched a live route but suppressed no violation.
     *
     * An entry in this list did match a live route pattern but none of its covered
     * rules fired a violation on that route, meaning it is carrying a waiver that
     * serves no purpose. Results are sorted ascending for determinism.
     *
     * @return array<int, string>
     */
    public function unused(): array
    {
        $stale = [];

        foreach ($this->entries as $index => $entry) {
            if (($this->matched[$index] ?? false) && !($this->used[$index] ?? false)) {
                $stale[] = sprintf('%s (suppressed nothing): %s', $entry->match, $entry->reason);
            }
        }

        sort($stale);

        return $stale;
    }

    /**
     * Determine whether a single allowlist entry matches the given route.
     *
     * An entry matches when its `match` value equals the route name exactly, or
     * when it matches the URI via `fnmatch()` shell-wildcard semantics.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\AllowlistEntry  $entry
     * @param  string|null  $routeName
     * @param  string  $uri
     * @return bool
     */
    private function entryMatches(AllowlistEntry $entry, ?string $routeName, string $uri): bool
    {
        if ($routeName !== null && $routeName === $entry->match) {
            return true;
        }

        return fnmatch($entry->match, $uri);
    }
}
