<?php

namespace Tests\Unit\RouteLinting\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Rules\LowercaseRule;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use Tests\TestCase;

/**
 * Tests for the LowercaseRule (R3) error rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LowercaseRule::class)]
class LowercaseRuleTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\RouteLinting\Rules\LowercaseRule */
    private LowercaseRule $rule;

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

        $this->rule   = new LowercaseRule;
        $this->config = new RuleConfig([], [], [], []);
    }

    /**
     * Test that a segment containing an uppercase letter produces one R3 error violation.
     *
     * @return void
     */
    public function testUppercaseSegmentIsFlagged(): void
    {
        // Arrange
        $route = new NormalisedRoute(
            uri: 'Users',
            methods: ['GET'],
            name: null,
            segments: ['Users'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('R3', $violations[0]->ruleId);
        static::assertSame(Severity::ERROR, $violations[0]->severity);
        static::assertSame('Users', $violations[0]->offendingSurface);
        static::assertNull($violations[0]->remediationHint);
    }

    /**
     * Test that an all-lowercase segment produces no R3 violation.
     *
     * @return void
     */
    public function testLowercaseSegmentIsNotFlagged(): void
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
     * Test that route-parameter segments wrapped in braces are ignored.
     *
     * @return void
     */
    public function testParameterSegmentsAreIgnored(): void
    {
        // Arrange — the sole segment is a route parameter
        $route = new NormalisedRoute(
            uri: '{User}',
            methods: ['GET'],
            name: null,
            segments: ['{User}'],
            parameters: ['User'],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that a camelCase segment (mixed case) is flagged as a violation.
     *
     * @return void
     */
    public function testCamelCaseSegmentIsFlagged(): void
    {
        // Arrange — userProfiles contains uppercase letters; both R2 and R3 fire independently
        $route = new NormalisedRoute(
            uri: 'userProfiles',
            methods: ['GET'],
            name: null,
            segments: ['userProfiles'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert — R3 produces one violation for the mixed-case segment
        static::assertCount(1, $violations);
        static::assertSame('R3', $violations[0]->ruleId);
        static::assertSame('userProfiles', $violations[0]->offendingSurface);
    }

    /**
     * Test that empty segments are ignored and do not produce violations.
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

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that rule id() returns 'R3' and severity() returns Severity::ERROR.
     *
     * @return void
     */
    public function testRuleMetadata(): void
    {
        static::assertSame('R3', $this->rule->id());
        static::assertSame(Severity::ERROR, $this->rule->severity());
    }
}
