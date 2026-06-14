<?php

namespace Tests\Unit\RouteLinting\Dto;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\AllowlistEntry;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use Tests\TestCase;

/**
 * Tests for the RuleConfig DTO.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RuleConfig::class)]
class RuleConfigTest extends TestCase
{
    /**
     * Test that all four surfaces are stored independently and round-trip
     * correctly, including the AllowlistEntry objects within exemptions.
     *
     * @return void
     */
    public function testHoldsThreeSeparateSurfacesAndHints(): void
    {
        // Arrange
        $denylist     = ['get', 'list', 'create'];
        $hints        = ['get' => 'Use GET /resources', 'list' => 'Use GET /resources'];
        $exemption    = new AllowlistEntry(match: 'users.legacy', reason: 'Pre-REST route kept for backward compat');
        $uncountables = ['data', 'media'];

        // Act
        $config = new RuleConfig(
            verbDenylist: $denylist,
            remediationHints: $hints,
            exemptions: [$exemption],
            uncountables: $uncountables,
        );

        // Assert — each surface is exactly the value passed in
        static::assertSame($denylist, $config->verbDenylist);
        static::assertSame($hints, $config->remediationHints);
        static::assertCount(1, $config->exemptions);
        static::assertSame($exemption, $config->exemptions[0]);
        static::assertSame($uncountables, $config->uncountables);

        // Assert AllowlistEntry properties round-trip through exemptions
        static::assertSame('users.legacy', $config->exemptions[0]->match);
        static::assertSame('Pre-REST route kept for backward compat', $config->exemptions[0]->reason);
    }

    /**
     * Test that a RuleConfig with an empty exemptions array is valid and
     * exemptions returns an empty array.
     *
     * @return void
     */
    public function testExemptionsDefaultEmptyIsRepresentable(): void
    {
        // Arrange & Act
        $config = new RuleConfig(
            verbDenylist: ['get'],
            remediationHints: [],
            exemptions: [],
            uncountables: [],
        );

        // Assert
        static::assertSame([], $config->exemptions);
    }
}
