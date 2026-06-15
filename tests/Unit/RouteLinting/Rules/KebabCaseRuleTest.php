<?php

namespace Tests\Unit\RouteLinting\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Rules\KebabCaseRule;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use Tests\TestCase;

/**
 * Tests for the KebabCaseRule (R2) error rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(KebabCaseRule::class)]
class KebabCaseRuleTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\RouteLinting\Rules\KebabCaseRule */
    private KebabCaseRule $rule;

    /** @var \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig */
    private RuleConfig $config;

    /**
     * Set up shared fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->rule   = new KebabCaseRule;
        $this->config = new RuleConfig([], [], [], []);
    }

    /**
     * Test that a camelCase segment produces one R2 error violation.
     *
     * @return void
     */
    public function testCamelCaseSegmentIsFlagged(): void
    {
        // Arrange
        $route = new NormalisedRoute(
            uri: 'userProfiles',
            methods: ['GET'],
            name: null,
            segments: ['userProfiles'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('R2', $violations[0]->ruleId);
        static::assertSame(Severity::ERROR, $violations[0]->severity);
        static::assertSame('userProfiles', $violations[0]->offendingSurface);
        static::assertNull($violations[0]->remediationHint);
    }

    /**
     * Test that a valid kebab-case segment produces no R2 violation.
     *
     * @return void
     */
    public function testKebabSegmentIsNotFlagged(): void
    {
        // Arrange
        $route = new NormalisedRoute(
            uri: 'user-profiles',
            methods: ['GET'],
            name: null,
            segments: ['user-profiles'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that route-parameter segments wrapped in braces are ignored.
     *
     * @return void
     */
    public function testParameterSegmentsAreIgnored(): void
    {
        // Arrange — the sole segment is a route parameter
        $route = new NormalisedRoute(
            uri: '{user}',
            methods: ['GET'],
            name: null,
            segments: ['{user}'],
            parameters: ['user'],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that a plain lowercase alphanumeric segment produces no violation.
     *
     * @return void
     */
    public function testPlainLowercaseSegmentIsNotFlagged(): void
    {
        // Arrange
        $route = new NormalisedRoute(
            uri: 'users',
            methods: ['GET'],
            name: null,
            segments: ['users'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that empty segments (from trailing or duplicate slashes) are ignored.
     *
     * @return void
     */
    public function testEmptySegmentsAreIgnored(): void
    {
        // Arrange — trailing slash produces an empty trailing segment
        $route = new NormalisedRoute(
            uri: 'users/',
            methods: ['GET'],
            name: null,
            segments: ['users', ''],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert — 'users' is valid kebab; '' is skipped; no violations
        static::assertEmpty($violations);
    }

    /**
     * Test that a non-kebab literal following a route-parameter segment is still flagged.
     *
     * Kills the Continue_->break mutant (#42): if the loop breaks on a param/empty segment
     * instead of continuing, any literal that follows it is never inspected. With a param
     * first then a camelCase literal, the original produces a violation while the mutant
     * produces none.
     *
     * @return void
     */
    public function testNonKebabSegmentAfterParameterIsFlagged(): void
    {
        // Arrange — '{user}' is first; 'userProfiles' follows and must still be inspected
        $route = new NormalisedRoute(
            uri: '{user}/userProfiles',
            methods: ['GET'],
            name: null,
            segments: ['{user}', 'userProfiles'],
            parameters: ['user'],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert — 'userProfiles' violates kebab-case despite the leading param
        static::assertCount(1, $violations);
        static::assertSame('R2', $violations[0]->ruleId);
        static::assertSame(Severity::ERROR, $violations[0]->severity);
        static::assertSame('userProfiles', $violations[0]->offendingSurface);
    }

    /**
     * Test that two non-kebab segments each produce their own R2 violation.
     *
     * Kills the ArrayOneItem mutant (#43): when more than one violation is produced, the
     * mutant returns only the first element. Asserting count = 2 and both offendingSurface
     * values ensures the complete violations array is returned.
     *
     * @return void
     */
    public function testTwoNonKebabSegmentsProduceTwoViolations(): void
    {
        // Arrange — both 'userProfiles' and 'some_other_stuff' violate kebab-case
        $route = new NormalisedRoute(
            uri: 'userProfiles/some_other_stuff',
            methods: ['GET'],
            name: null,
            segments: ['userProfiles', 'some_other_stuff'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert — two violations, one per offending segment, in segment order
        static::assertCount(2, $violations);
        static::assertSame('R2', $violations[0]->ruleId);
        static::assertSame('userProfiles', $violations[0]->offendingSurface);
        static::assertSame('R2', $violations[1]->ruleId);
        static::assertSame('some_other_stuff', $violations[1]->offendingSurface);
    }

    /**
     * Test that rule id() returns 'R2' and severity() returns Severity::ERROR.
     *
     * @return void
     */
    public function testRuleMetadata(): void
    {
        static::assertSame('R2', $this->rule->id());
        static::assertSame(Severity::ERROR, $this->rule->severity());
    }
}
