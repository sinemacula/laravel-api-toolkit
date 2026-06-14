<?php

namespace Tests\Unit\RouteLinting\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Rules\StandardMethodsRule;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use Tests\TestCase;

/**
 * Tests for the StandardMethodsRule (R7) error rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(StandardMethodsRule::class)]
class StandardMethodsRuleTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\RouteLinting\Rules\StandardMethodsRule */
    private StandardMethodsRule $rule;

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

        $this->rule   = new StandardMethodsRule;
        $this->config = new RuleConfig([], [], [], []);
    }

    /**
     * Test that a route whose methods include a non-standard verb produces one R7 error violation.
     *
     * @return void
     */
    public function testNonStandardMethodIsFlagged(): void
    {
        // Arrange — PURGE is not in the standard set
        $route = new NormalisedRoute(
            uri: 'users',
            methods: ['PURGE'],
            name: null,
            segments: ['users'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('R7', $violations[0]->ruleId);
        static::assertSame(Severity::ERROR, $violations[0]->severity);
        static::assertSame('PURGE', $violations[0]->offendingSurface);
        static::assertNull($violations[0]->remediationHint);
    }

    /**
     * Test that a route with only standard methods produces no R7 violation.
     *
     * @return void
     */
    public function testStandardMethodsAreNotFlagged(): void
    {
        // Arrange — GET and HEAD are both in the standard set
        $route = new NormalisedRoute(
            uri: 'users',
            methods: ['GET', 'HEAD'],
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
     * Test that rule id() returns 'R7' and severity() returns Severity::ERROR.
     *
     * @return void
     */
    public function testRuleMetadata(): void
    {
        static::assertSame('R7', $this->rule->id());
        static::assertSame(Severity::ERROR, $this->rule->severity());
    }
}
