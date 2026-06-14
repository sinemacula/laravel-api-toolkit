<?php

namespace SineMacula\ApiToolkit\RouteLinting\Contracts;

use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;

/**
 * Outbound port for supplying the rule-configuration DTO.
 *
 * Implementers read the three strictly-separate config surfaces (verb
 * denylist, exemption allowlist, inflector uncountables) and assemble them
 * into a single {@see RuleConfig} DTO for consumption by the rule engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface RuleConfiguration
{
    /**
     * Build the rule-config DTO from the three separate config surfaces.
     *
     * @return \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig
     *
     * @throws \SineMacula\ApiToolkit\RouteLinting\Exceptions\StaleWaiverException
     */
    public function load(): RuleConfig;
}
