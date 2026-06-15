<?php

namespace Tests\Unit\RouteLinting\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Configuration\ConfigRuleConfiguration;
use SineMacula\ApiToolkit\RouteLinting\Exceptions\StaleWaiverException;
use Tests\TestCase;

/**
 * Tests for ConfigRuleConfiguration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ConfigRuleConfiguration::class)]
class ConfigRuleConfigurationTest extends TestCase
{
    /**
     * Test that load() returns an empty exemptions array when no app overrides
     * are present (the shipped-default zero-exemption state).
     *
     * @return void
     */
    public function testDefaultExemptionsAreEmpty(): void
    {
        $adapter = new ConfigRuleConfiguration;
        $config  = $adapter->load();

        static::assertSame([], $config->exemptions);
    }

    /**
     * Test that load() reads the three separate config surfaces independently
     * and assembles them into a RuleConfig with matching values.
     *
     * @return void
     */
    public function testReadsThreeSeparateSurfaces(): void
    {
        config()->set('api-toolkit.route_linting.verb_denylist', ['get', 'fetch']);
        config()->set('api-toolkit.route_linting.remediation_hints', ['get' => 'Use a noun resource instead.']);
        config()->set('api-toolkit.route_linting.exemptions', [
            ['match' => 'users.store', 'reason' => 'Legacy endpoint kept for backward compatibility.'],
        ]);
        config()->set('api-toolkit.route_linting.uncountables', ['media', 'data']);

        $adapter = new ConfigRuleConfiguration;
        $result  = $adapter->load();

        static::assertSame(['get', 'fetch'], $result->verbDenylist);
        static::assertSame(['get' => 'Use a noun resource instead.'], $result->remediationHints);
        static::assertCount(1, $result->exemptions);
        static::assertSame('users.store', $result->exemptions[0]->match);
        static::assertSame('Legacy endpoint kept for backward compatibility.', $result->exemptions[0]->reason);
        static::assertSame(['media', 'data'], $result->uncountables);
    }

    /**
     * Test that load() throws StaleWaiverException when an exemption entry has
     * no reason, enforcing the required-reason invariant.
     *
     * @return void
     */
    public function testExemptionWithoutReasonIsRejected(): void
    {
        config()->set('api-toolkit.route_linting.exemptions', [
            ['match' => 'users.store'],
        ]);

        $this->expectException(StaleWaiverException::class);
        $this->expectExceptionMessage('Allowlist entry "users.store" is missing a required reason.');

        $adapter = new ConfigRuleConfiguration;
        $adapter->load();
    }

    /**
     * Test that load() throws StaleWaiverException when an exemption entry has
     * an empty (whitespace-only) reason.
     *
     * @return void
     */
    public function testExemptionWithEmptyReasonIsRejected(): void
    {
        config()->set('api-toolkit.route_linting.exemptions', [
            ['match' => 'users.store', 'reason' => '   '],
        ]);

        $this->expectException(StaleWaiverException::class);
        $this->expectExceptionMessage('Allowlist entry "users.store" is missing a required reason.');

        $adapter = new ConfigRuleConfiguration;
        $adapter->load();
    }

    /**
     * Test that load() returns empty arrays for all surfaces when config keys
     * are entirely absent.
     *
     * @return void
     */
    public function testMissingConfigKeysDefaultToEmptyArrays(): void
    {
        config()->set('api-toolkit.route_linting', null);

        $adapter = new ConfigRuleConfiguration;
        $result  = $adapter->load();

        static::assertSame([], $result->verbDenylist);
        static::assertSame([], $result->remediationHints);
        static::assertSame([], $result->exemptions);
        static::assertSame([], $result->uncountables);
    }

    /**
     * Test that an exemption entry with a `rules` key produces an AllowlistEntry
     * whose covers() is scoped to those rule IDs only.
     *
     * @return void
     */
    public function testExemptionWithRulesKeyProducesScopedEntry(): void
    {
        config()->set('api-toolkit.route_linting.exemptions', [
            ['match' => 'users.store', 'reason' => 'Scoped waiver.', 'rules' => ['R9', 'R3']],
        ]);

        $adapter = new ConfigRuleConfiguration;
        $result  = $adapter->load();

        static::assertCount(1, $result->exemptions);

        $entry = $result->exemptions[0];

        static::assertSame(['R9', 'R3'], $entry->rules);
        static::assertTrue($entry->covers('R9'));
        static::assertTrue($entry->covers('R3'));
        static::assertFalse($entry->covers('R1'));
    }

    /**
     * Test that an exemption entry without a `rules` key produces an
     * AllowlistEntry that covers all rules (backward-compatible default).
     *
     * @return void
     */
    public function testExemptionWithoutRulesKeyCoversAllRules(): void
    {
        config()->set('api-toolkit.route_linting.exemptions', [
            ['match' => 'orders.index', 'reason' => 'All-rules waiver.'],
        ]);

        $adapter = new ConfigRuleConfiguration;
        $result  = $adapter->load();

        static::assertCount(1, $result->exemptions);

        $entry = $result->exemptions[0];

        static::assertSame([], $entry->rules);
        static::assertTrue($entry->covers('R9'));
        static::assertTrue($entry->covers('R1'));
    }

    /**
     * Test that a non-array `rules` value in an exemption config entry is
     * treated as empty (all rules covered), so a corrupt config key does not
     * crash the adapter.
     *
     * @return void
     */
    public function testNonArrayRulesValueDefaultsToEmpty(): void
    {
        config()->set('api-toolkit.route_linting.exemptions', [
            ['match' => 'reports.index', 'reason' => 'Fallback waiver.', 'rules' => 'not-an-array'],
        ]);

        $adapter = new ConfigRuleConfiguration;
        $result  = $adapter->load();

        static::assertCount(1, $result->exemptions);
        static::assertSame([], $result->exemptions[0]->rules);
    }
}
