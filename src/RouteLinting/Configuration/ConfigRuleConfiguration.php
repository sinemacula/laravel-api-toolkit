<?php

namespace SineMacula\ApiToolkit\RouteLinting\Configuration;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\RouteLinting\Contracts\RuleConfiguration;
use SineMacula\ApiToolkit\RouteLinting\Dto\AllowlistEntry;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\Exceptions\StaleWaiverException;

/**
 * Config-backed adapter for the RuleConfiguration port.
 *
 * Reads the `api-toolkit.route_linting.*` config section and assembles the
 * three strictly-separate surfaces — verb denylist, exemption allowlist, and
 * inflector uncountables — into a {@see RuleConfig} DTO. Every allowlist entry
 * must carry a non-empty written reason; entries that violate this invariant
 * cause a {@see StaleWaiverException} to be thrown immediately so a reasonless
 * waiver never enters the effective configuration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ConfigRuleConfiguration implements RuleConfiguration
{
    /**
     * Build the rule-config DTO from the three separate config surfaces.
     *
     * @return \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig
     *
     * @throws \SineMacula\ApiToolkit\RouteLinting\Exceptions\StaleWaiverException
     */
    #[\Override]
    public function load(): RuleConfig
    {
        $verbDenylist     = Config::get('api-toolkit.route_linting.verb_denylist');
        $remediationHints = Config::get('api-toolkit.route_linting.remediation_hints');
        $rawExemptions    = Config::get('api-toolkit.route_linting.exemptions');
        $uncountables     = Config::get('api-toolkit.route_linting.uncountables');

        return new RuleConfig(
            verbDenylist: is_array($verbDenylist) ? $verbDenylist : [],
            remediationHints: is_array($remediationHints) ? $remediationHints : [],
            exemptions: $this->buildExemptions(is_array($rawExemptions) ? $rawExemptions : []),
            uncountables: is_array($uncountables) ? $uncountables : [],
        );
    }

    /**
     * Map raw config exemption entries to AllowlistEntry DTOs.
     *
     * Rejects any entry where `match` is absent or `reason` is missing,
     * not a string, or consists only of whitespace.
     *
     * @param  array<int|string, mixed>  $raw
     * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Dto\AllowlistEntry>
     *
     * @throws \SineMacula\ApiToolkit\RouteLinting\Exceptions\StaleWaiverException
     */
    private function buildExemptions(array $raw): array
    {
        $entries = [];

        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['match']) || !is_string($item['match'])) {
                throw new StaleWaiverException('Allowlist entry is missing a required match key.');
            }

            $match  = $item['match'];
            $reason = $item['reason'] ?? null;

            if (!is_string($reason) || trim($reason) === '') {
                throw new StaleWaiverException(sprintf('Allowlist entry "%s" is missing a required reason.', $match));
            }

            $entries[] = new AllowlistEntry($match, $reason);
        }

        return $entries;
    }
}
