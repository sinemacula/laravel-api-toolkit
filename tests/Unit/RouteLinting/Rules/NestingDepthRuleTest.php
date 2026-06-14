<?php

namespace Tests\Unit\RouteLinting\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Rules\NestingDepthRule;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use Tests\TestCase;

/**
 * Tests for the NestingDepthRule (R11) warning rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(NestingDepthRule::class)]
class NestingDepthRuleTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\RouteLinting\Rules\NestingDepthRule */
    private NestingDepthRule $rule;

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

        $this->rule   = new NestingDepthRule;
        $this->config = new RuleConfig([], [], [], []);
    }

    /**
     * Test that a four-collection-level route produces one R11 warning violation.
     *
     * users/{user}/posts/{post}/comments/{comment}/likes/{like} has four literal
     * resource segments: users, posts, comments, likes.
     *
     * @return void
     */
    public function testFourLevelRouteIsFlagged(): void
    {
        // Arrange
        $uri   = 'users/{user}/posts/{post}/comments/{comment}/likes/{like}';
        $route = new NormalisedRoute(
            uri: $uri,
            methods: ['GET'],
            name: 'users.posts.comments.likes.index',
            segments: ['users', '{user}', 'posts', '{post}', 'comments', '{comment}', 'likes', '{like}'],
            parameters: ['user', 'post', 'comment', 'like'],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('R11', $violations[0]->ruleId);
        static::assertSame(Severity::WARNING, $violations[0]->severity);
        static::assertSame($uri, $violations[0]->offendingSurface);
        static::assertNull($violations[0]->remediationHint);
    }

    /**
     * Test that a three-collection-level route produces no R11 violation.
     *
     * users/{user}/posts/{post}/comments/{comment} has three literal resource
     * segments: users, posts, comments — exactly at the threshold, so no warning.
     *
     * @return void
     */
    public function testThreeLevelRouteIsNotFlagged(): void
    {
        // Arrange
        $route = new NormalisedRoute(
            uri: 'users/{user}/posts/{post}/comments/{comment}',
            methods: ['GET'],
            name: 'users.posts.comments.index',
            segments: ['users', '{user}', 'posts', '{post}', 'comments', '{comment}'],
            parameters: ['user', 'post', 'comment'],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that 'api' and version prefix segments are excluded from the depth count.
     *
     * api/v1/users/{user}/posts/{post}/comments/{comment}/likes/{like} has four
     * resource literals after excluding 'api' and 'v1', so it is flagged.
     * Conversely api/v1/users/{user}/posts/{post}/comments/{comment} has three
     * and must not be flagged.
     *
     * @return void
     */
    public function testApiVersionPrefixExcludedFromDepth(): void
    {
        // Arrange — four resource levels with api/v1 prefix (flagged)
        $fourLevelUri   = 'api/v1/users/{user}/posts/{post}/comments/{comment}/likes/{like}';
        $fourLevelRoute = new NormalisedRoute(
            uri: $fourLevelUri,
            methods: ['GET'],
            name: null,
            segments: ['api', 'v1', 'users', '{user}', 'posts', '{post}', 'comments', '{comment}', 'likes', '{like}'],
            parameters: ['user', 'post', 'comment', 'like'],
        );

        // Arrange — three resource levels with api/v1 prefix (clean)
        $threeLevelRoute = new NormalisedRoute(
            uri: 'api/v1/users/{user}/posts/{post}/comments/{comment}',
            methods: ['GET'],
            name: null,
            segments: ['api', 'v1', 'users', '{user}', 'posts', '{post}', 'comments', '{comment}'],
            parameters: ['user', 'post', 'comment'],
        );

        // Act
        $flaggedViolations = $this->rule->inspect($fourLevelRoute, $this->config);
        $cleanViolations   = $this->rule->inspect($threeLevelRoute, $this->config);

        // Assert — four levels after prefix exclusion triggers warning
        static::assertCount(1, $flaggedViolations);
        static::assertSame($fourLevelUri, $flaggedViolations[0]->offendingSurface);

        // Assert — three levels after prefix exclusion is clean
        static::assertEmpty($cleanViolations);
    }

    /**
     * Test that rule id() returns 'R11' and severity() returns Severity::WARNING.
     *
     * @return void
     */
    public function testRuleMetadata(): void
    {
        static::assertSame('R11', $this->rule->id());
        static::assertSame(Severity::WARNING, $this->rule->severity());
    }
}
