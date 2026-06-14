<?php

namespace Tests\Unit\RouteLinting\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Rules\ApiResourceAlignmentRule;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use Tests\TestCase;

/**
 * Tests for the ApiResourceAlignmentRule (R9) warning rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResourceAlignmentRule::class)]
class ApiResourceAlignmentRuleTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\RouteLinting\Rules\ApiResourceAlignmentRule */
    private ApiResourceAlignmentRule $rule;

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

        $this->rule   = new ApiResourceAlignmentRule;
        $this->config = new RuleConfig([], [], [], []);
    }

    /**
     * Test that a route whose final segment is 'edit' produces one R9 warning violation.
     *
     * @return void
     */
    public function testEditActionIsFlagged(): void
    {
        // Arrange — GET /photos/{photo}/edit; 'edit' is the final literal segment
        $route = new NormalisedRoute(
            uri: 'photos/{photo}/edit',
            methods: ['GET'],
            name: 'photos.edit',
            segments: ['photos', '{photo}', 'edit'],
            parameters: ['photo'],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('R9', $violations[0]->ruleId);
        static::assertSame(Severity::WARNING, $violations[0]->severity);
        static::assertSame('edit', $violations[0]->offendingSurface);
        static::assertNull($violations[0]->remediationHint);
    }

    /**
     * Test that a route whose final segment is 'create' produces one R9 warning violation.
     *
     * @return void
     */
    public function testCreateActionIsFlagged(): void
    {
        // Arrange — GET /photos/create; 'create' is the final literal segment
        $route = new NormalisedRoute(
            uri: 'photos/create',
            methods: ['GET'],
            name: 'photos.create',
            segments: ['photos', 'create'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('R9', $violations[0]->ruleId);
        static::assertSame(Severity::WARNING, $violations[0]->severity);
        static::assertSame('create', $violations[0]->offendingSurface);
        static::assertNull($violations[0]->remediationHint);
    }

    /**
     * Test that the canonical show route GET /photos/{photo} produces no R9 violation.
     *
     * The final segment is the route parameter `{photo}`, not a literal 'create' or 'edit'.
     *
     * @return void
     */
    public function testCanonicalShowIsNotFlagged(): void
    {
        // Arrange — GET /photos/{photo}; final segment is a parameter
        $route = new NormalisedRoute(
            uri: 'photos/{photo}',
            methods: ['GET'],
            name: 'photos.show',
            segments: ['photos', '{photo}'],
            parameters: ['photo'],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that a literal 'create' in a non-terminal position does not produce a violation.
     *
     * Only the final literal segment is checked to keep precision high.
     *
     * @return void
     */
    public function testNonFinalCreateSegmentIsNotFlagged(): void
    {
        // Arrange — 'create' is not the last literal; 'items' follows it
        $route = new NormalisedRoute(
            uri: 'create/items',
            methods: ['GET'],
            name: null,
            segments: ['create', 'items'],
            parameters: [],
        );

        // Act
        $violations = $this->rule->inspect($route, $this->config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that rule id() returns 'R9' and severity() returns Severity::WARNING.
     *
     * @return void
     */
    public function testRuleMetadata(): void
    {
        static::assertSame('R9', $this->rule->id());
        static::assertSame(Severity::WARNING, $this->rule->severity());
    }
}
