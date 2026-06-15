<?php

namespace Tests\Unit\RouteLinting\Dto;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\RouteSuppression;
use Tests\TestCase;

/**
 * Tests for the RouteSuppression DTO.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RouteSuppression::class)]
class RouteSuppressionTest extends TestCase
{
    /**
     * Test that covers() returns true for any rule ID when the rules list is
     * empty, i.e. the suppression targets all rules.
     *
     * @return void
     */
    public function testCoversReturnsTrueForAnyRuleWhenRulesListIsEmpty(): void
    {
        $suppression = new RouteSuppression([], 'Suppresses all rules.');

        static::assertTrue($suppression->covers('R1'));
        static::assertTrue($suppression->covers('R9'));
        static::assertTrue($suppression->covers('PLURAL_COLLECTIONS'));
    }

    /**
     * Test that covers() returns true when the given rule ID is explicitly
     * present in the suppression's rules list.
     *
     * @return void
     */
    public function testCoversReturnsTrueForExplicitlyListedRule(): void
    {
        $suppression = new RouteSuppression(['R9', 'R3'], 'Specific rules suppressed.');

        static::assertTrue($suppression->covers('R9'));
        static::assertTrue($suppression->covers('R3'));
    }

    /**
     * Test that covers() returns false when the given rule ID is not in the
     * suppression's non-empty rules list.
     *
     * @return void
     */
    public function testCoversReturnsFalseForRuleNotInList(): void
    {
        $suppression = new RouteSuppression(['R9'], 'Only R9 suppressed.');

        static::assertFalse($suppression->covers('R1'));
        static::assertFalse($suppression->covers('PLURAL_COLLECTIONS'));
    }

    /**
     * Test that the constructor correctly exposes rules and reason as public
     * properties.
     *
     * @return void
     */
    public function testExposesRulesAndReason(): void
    {
        $suppression = new RouteSuppression(['R9', 'R3'], 'Legacy route.');

        static::assertSame(['R9', 'R3'], $suppression->rules);
        static::assertSame('Legacy route.', $suppression->reason);
    }
}
