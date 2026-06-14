<?php

namespace SineMacula\ApiToolkit\RouteLinting;

use SineMacula\ApiToolkit\RouteLinting\Dto\AllowlistEntry;

/**
 * Domain service that suppresses waived violations and tracks stale entries.
 *
 * Constructed from the exemption entries supplied by the rule-configuration
 * port, this service answers whether a given route (identified by its optional
 * name and its URI) is covered by any allowlist entry, and records which
 * entries were matched so unmatched entries can be reported as stale waivers
 * after the full route table has been inspected. An empty allowlist is the
 * shipped default; in that state every route is uninspected and no stale
 * entries are ever reported.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExemptionAllowlist
{
    /** @var array<int, \SineMacula\ApiToolkit\RouteLinting\Dto\AllowlistEntry> */
    private readonly array $entries;

    /** @var array<int, bool> Index positions of entries that matched at least one route */
    private array $matched = [];

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
     * Determine whether the given route is waived by any allowlist entry.
     *
     * Matches by exact route name first, then by URI shell-wildcard pattern via
     * `fnmatch()`. The first matching entry is recorded as matched so it does
     * not later appear in `unmatched()`. Subsequent calls that hit the same
     * entry do not alter the match record.
     *
     * @param  string|null  $routeName
     * @param  string  $uri
     * @return bool
     */
    public function isExempt(?string $routeName, string $uri): bool
    {
        foreach ($this->entries as $index => $entry) {
            if ($this->entryMatches($entry, $routeName, $uri)) {
                $this->matched[$index] = true;

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
            if (!isset($this->matched[$index])) {
                $stale[] = $entry->match;
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
