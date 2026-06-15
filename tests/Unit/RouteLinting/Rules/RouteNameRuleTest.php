<?php

namespace Tests\Unit\RouteLinting\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Rules\RouteNameRule;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use Tests\TestCase;

/**
 * Tests for the RouteNameRule (R8) warning rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RouteNameRule::class)]
class RouteNameRuleTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\RouteLinting\Rules\RouteNameRule */
    private RouteNameRule $rule;

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

        $this->rule   = new RouteNameRule;
        $this->config = new RuleConfig([], [], [], []);
    }

    /**
     * Test that a named route not matching {resource}.{action} produces one R8 warning violation.
     *
     * @return void
     */
    public function testNonConventionalNameIsFlagged(): void
    {
        // Arrange — 'getAll' is not in the allowed actions set
        $route = new NormalisedRoute(
            uri: 'users',
            methods: ['GET'],
            name: 'users.getAll',
            segments: ['users'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('R8', $violations[0]->ruleId);
        static::assertSame(Severity::WARNING, $violations[0]->severity);
        static::assertSame('users.getAll', $violations[0]->offendingSurface);
        static::assertNull($violations[0]->remediationHint);
    }

    /**
     * Test that a route named with a conventional {resource}.{action} produces no R8 violation.
     *
     * @return void
     */
    public function testConventionalNameIsNotFlagged(): void
    {
        // Arrange — 'index' is an allowed action
        $route = new NormalisedRoute(
            uri: 'users',
            methods: ['GET'],
            name: 'users.index',
            segments: ['users'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that an unnamed route produces no R8 violation.
     *
     * @return void
     */
    public function testUnnamedRouteIsSkipped(): void
    {
        // Arrange — name is null; rule must skip without flagging
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
     * Test that a nested resource name with a valid action produces no R8 violation.
     *
     * @return void
     */
    public function testNestedResourceNameIsNotFlagged(): void
    {
        // Arrange — 'users.posts.show': resource='users.posts', action='show'
        $route = new NormalisedRoute(
            uri: 'users/{user}/posts/{post}',
            methods: ['GET'],
            name: 'users.posts.show',
            segments: ['users', '{user}', 'posts', '{post}'],
            parameters: ['user', 'post'],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that a name with no dot separator is flagged as a violation.
     *
     * @return void
     */
    public function testNameWithoutDotIsFlagged(): void
    {
        // Arrange — 'login' has no dot; no resource.action structure
        $route = new NormalisedRoute(
            uri: 'login',
            methods: ['GET'],
            name: 'login',
            segments: ['login'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('R8', $violations[0]->ruleId);
        static::assertSame(Severity::WARNING, $violations[0]->severity);
        static::assertSame('login', $violations[0]->offendingSurface);
    }

    /**
     * Test that a name whose only dot is at position zero (empty resource part) is flagged.
     *
     * A name such as '.index' has $lastDot === 0, making the resource part empty; the rule
     * must flag it. This pins the $lastDot === 0 boundary and the consequent return false.
     *
     * @return void
     */
    public function testNameWithLeadingDotIsFlagged(): void
    {
        // Arrange — '.index' has the dot at position 0; resource would be empty
        $route = new NormalisedRoute(
            uri: 'index',
            methods: ['GET'],
            name: '.index',
            segments: ['index'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert — the name must be flagged: offendingSurface is the full name
        static::assertCount(1, $violations);
        static::assertSame('R8', $violations[0]->ruleId);
        static::assertSame(Severity::WARNING, $violations[0]->severity);
        static::assertSame('.index', $violations[0]->offendingSurface);
    }

    /**
     * Test that every canonical REST action is accepted without an R8 violation.
     *
     * Pins each element of the ALLOWED_ACTIONS set so that mutations swapping or
     * extending the set are caught by at least one assertion.
     *
     * @return void
     */
    public function testAllAllowedActionsAreAccepted(): void
    {
        // Arrange — the seven canonical actions
        $allowedActions = ['index', 'show', 'store', 'update', 'destroy', 'create', 'edit'];

        foreach ($allowedActions as $action) {
            $route = new NormalisedRoute(
                uri: 'resources',
                methods: ['GET'],
                name: 'resources.' . $action,
                segments: ['resources'],
                parameters: [],
            );

            $violations = $this->rule->inspect($route, $this->config);

            static::assertEmpty($violations, "Action '{$action}' should be accepted but produced a violation.");
        }
    }

    /**
     * Test that a disallowed action produces an R8 violation with the full name as the offending surface.
     *
     * Pins the in_array(action, ALLOWED_ACTIONS) check. Using a single-segment resource
     * ('a') ensures the resource part is non-empty after any plausible substr variant.
     *
     * @return void
     */
    public function testDisallowedActionOnShortResourceIsFlagged(): void
    {
        // Arrange — 'a.list': resource='a' (one char), action='list' (not allowed)
        $route = new NormalisedRoute(
            uri: 'a',
            methods: ['GET'],
            name: 'a.list',
            segments: ['a'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('R8', $violations[0]->ruleId);
        static::assertSame(Severity::WARNING, $violations[0]->severity);
        static::assertSame('a.list', $violations[0]->offendingSurface);
    }

    /**
     * Test that rule id() returns 'R8' and severity() returns Severity::WARNING.
     *
     * @return void
     */
    public function testRuleMetadata(): void
    {
        static::assertSame('R8', $this->rule->id());
        static::assertSame(Severity::WARNING, $this->rule->severity());
    }
}
