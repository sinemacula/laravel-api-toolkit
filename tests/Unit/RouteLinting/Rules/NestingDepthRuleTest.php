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
     * Test that a route with four literals but fewer route parameters is correctly flagged.
     *
     * This kills the LogicalOrNegation mutant (#46) that inverts the segment-skip condition
     * so that literals are skipped and parameters are counted instead. With only two
     * parameters but four literal segments, the original produces a violation while
     * the mutant (counting params, not literals) produces none.
     *
     * @return void
     */
    public function testFourLiteralsWithFewerParamsIsFlagged(): void
    {
        // Arrange — four literal segments (users, posts, comments, tags) and two params
        $uri   = 'users/{user}/posts/comments/tags';
        $route = new NormalisedRoute(
            uri: $uri,
            methods: ['GET'],
            name: null,
            segments: ['users', '{user}', 'posts', 'comments', 'tags'],
            parameters: ['user'],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert — four literal segments exceeds the threshold of three
        static::assertCount(1, $violations);
        static::assertSame('R11', $violations[0]->ruleId);
        static::assertSame(Severity::WARNING, $violations[0]->severity);
        static::assertSame($uri, $violations[0]->offendingSurface);
    }

    /**
     * Test that route-parameter segments are not counted toward nesting depth.
     *
     * This kills the LogicalOrSingleSubExprNegation mutant (#47) that changes the
     * condition so non-parameter segments (literals) are skipped and parameters
     * are counted. Four parameters with only three literal segments: original
     * counts three (no violation), mutant counts four (violation).
     *
     * @return void
     */
    public function testParameterSegmentsAreNotCountedTowardDepth(): void
    {
        // Arrange — three literal segments (users, posts, comments) and four params
        $route = new NormalisedRoute(
            uri: '{a}/{b}/users/{c}/posts/comments/{d}',
            methods: ['GET'],
            name: null,
            segments: ['{a}', '{b}', 'users', '{c}', 'posts', 'comments', '{d}'],
            parameters: ['a', 'b', 'c', 'd'],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert — only three literal segments; must not trigger R11
        static::assertEmpty($violations);
    }

    /**
     * Test that a segment containing 'v2' in a non-prefix position is counted in the depth.
     *
     * Kills PregMatchRemoveCaret mutant (#48): without the leading anchor the regex
     * '/v\d+$/i' would also match 'xv2', causing that segment to be skipped. With the
     * correct anchored regex '/^v\d+$/i' only pure version tokens are excluded.
     *
     * @return void
     */
    public function testSegmentContainingVersionStringIsCountedAsLiteral(): void
    {
        // Arrange — 'xv2' looks version-like but is not a pure version token;
        // four literals including 'xv2' must all be counted to trigger R11
        $uri   = 'users/posts/comments/xv2';
        $route = new NormalisedRoute(
            uri: $uri,
            methods: ['GET'],
            name: null,
            segments: ['users', 'posts', 'comments', 'xv2'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert — all four segments are literal, depth = 4 > 3
        static::assertCount(1, $violations);
        static::assertSame('R11', $violations[0]->ruleId);
        static::assertSame($uri, $violations[0]->offendingSurface);
    }

    /**
     * Test that a segment beginning with a version token but having a suffix is counted as a literal.
     *
     * Kills PregMatchRemoveDollar mutant (#49): without the trailing anchor the regex
     * '/^v\d+/i' would match 'v2extra', skipping that segment. With the correct
     * fully-anchored regex '/^v\d+$/i', 'v2extra' is not a version prefix and is counted.
     *
     * @return void
     */
    public function testVersionPrefixWithSuffixIsCountedAsLiteral(): void
    {
        // Arrange — 'v2extra' starts with a version-like token but is not a pure version;
        // four literals including 'v2extra' must all be counted to trigger R11
        $uri   = 'users/posts/comments/v2extra';
        $route = new NormalisedRoute(
            uri: $uri,
            methods: ['GET'],
            name: null,
            segments: ['users', 'posts', 'comments', 'v2extra'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert — depth = 4 > 3; 'v2extra' must not be excluded
        static::assertCount(1, $violations);
        static::assertSame($uri, $violations[0]->offendingSurface);
    }

    /**
     * Test that an uppercase version token (e.g. 'V2') is excluded from the depth count.
     *
     * Kills PregMatchRemoveFlags mutant (#50): without the 'i' flag, the regex
     * '/^v\d+$/' would not match 'V2', causing it to be counted as a literal segment.
     * With the case-insensitive flag, 'V2' is a valid version prefix and is excluded.
     *
     * @return void
     */
    public function testUppercaseVersionTokenIsExcludedFromDepth(): void
    {
        // Arrange — three literal resource segments plus uppercase 'V2' prefix;
        // 'V2' must be excluded so depth = 3, which is exactly the threshold (no violation)
        $route = new NormalisedRoute(
            uri: 'V2/users/posts/comments',
            methods: ['GET'],
            name: null,
            segments: ['V2', 'users', 'posts', 'comments'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert — 'V2' excluded; depth = 3; must not produce a violation
        static::assertEmpty($violations);
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
