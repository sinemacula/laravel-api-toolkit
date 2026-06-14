<?php

namespace Tests\Unit\RouteLinting\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Rules\SlashSanityRule;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use Tests\TestCase;

/**
 * Tests for the SlashSanityRule (R5) error rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SlashSanityRule::class)]
class SlashSanityRuleTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\RouteLinting\Rules\SlashSanityRule */
    private SlashSanityRule $rule;

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

        $this->rule   = new SlashSanityRule;
        $this->config = new RuleConfig([], [], [], []);
    }

    /**
     * Test that a URI with a trailing slash produces one R5 error violation.
     *
     * @return void
     */
    public function testTrailingSlashIsFlagged(): void
    {
        // Arrange
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
        static::assertCount(1, $violations);
        static::assertSame('R5', $violations[0]->ruleId);
        static::assertSame(Severity::ERROR, $violations[0]->severity);
        static::assertSame('users/', $violations[0]->offendingSurface);
        static::assertNull($violations[0]->remediationHint);
    }

    /**
     * Test that a URI with a duplicate slash produces one R5 error violation.
     *
     * @return void
     */
    public function testDuplicateSlashIsFlagged(): void
    {
        // Arrange
        $route = new NormalisedRoute(
            uri: 'users//posts',
            methods: ['GET'],
            name: null,
            segments: ['users', '', 'posts'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('R5', $violations[0]->ruleId);
        static::assertSame(Severity::ERROR, $violations[0]->severity);
        static::assertSame('users//posts', $violations[0]->offendingSurface);
        static::assertNull($violations[0]->remediationHint);
    }

    /**
     * Test that a URI with both a duplicate and a trailing slash emits only one violation.
     *
     * @return void
     */
    public function testBothDefectsEmitOneViolation(): void
    {
        // Arrange
        $route = new NormalisedRoute(
            uri: 'users//posts/',
            methods: ['GET'],
            name: null,
            segments: ['users', '', 'posts', ''],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert — one violation regardless of defect count
        static::assertCount(1, $violations);
    }

    /**
     * Test that a clean URI produces no R5 violation.
     *
     * @return void
     */
    public function testCleanUriIsNotFlagged(): void
    {
        // Arrange
        $route = new NormalisedRoute(
            uri: 'users/{user}',
            methods: ['GET'],
            name: null,
            segments: ['users', '{user}'],
            parameters: ['user'],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that the root path produces no R5 violation.
     *
     * @return void
     */
    public function testRootPathIsNotFlagged(): void
    {
        // Arrange
        $route = new NormalisedRoute(
            uri: '/',
            methods: ['GET'],
            name: null,
            segments: ['', ''],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that an empty URI produces no R5 violation.
     *
     * @return void
     */
    public function testEmptyUriIsNotFlagged(): void
    {
        // Arrange
        $route = new NormalisedRoute(
            uri: '',
            methods: ['GET'],
            name: null,
            segments: [],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that rule id() returns 'R5' and severity() returns Severity::ERROR.
     *
     * @return void
     */
    public function testRuleMetadata(): void
    {
        static::assertSame('R5', $this->rule->id());
        static::assertSame(Severity::ERROR, $this->rule->severity());
    }
}
