<?php

namespace Tests\Unit\RouteLinting\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Contracts\Inflector;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Rules\PluralCollectionsRule;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use Tests\TestCase;

/**
 * Tests for the PluralCollectionsRule (R4) error rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PluralCollectionsRule::class)]
class PluralCollectionsRuleTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\RouteLinting\Rules\PluralCollectionsRule */
    private PluralCollectionsRule $rule;

    /**
     * Set up shared fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->rule = new PluralCollectionsRule($this->makeInflector());
    }

    /**
     * Test that a singular collection segment preceding a parameter is flagged.
     *
     * @return void
     */
    public function testSingularCollectionIsFlagged(): void
    {
        // Arrange — `user` precedes `{user}` so it is a collection segment
        $route = new NormalisedRoute(
            uri: 'user/{user}',
            methods: ['GET'],
            name: null,
            segments: ['user', '{user}'],
            parameters: ['user'],
        );

        $config = new RuleConfig([], [], [], []);

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('R4', $violations[0]->ruleId);
        static::assertSame(Severity::ERROR, $violations[0]->severity);
        static::assertSame('user', $violations[0]->offendingSurface);
        static::assertNull($violations[0]->remediationHint);
    }

    /**
     * Test that a plural collection segment preceding a parameter is not flagged.
     *
     * @return void
     */
    public function testPluralCollectionIsNotFlagged(): void
    {
        // Arrange — `users` is already plural
        $route = new NormalisedRoute(
            uri: 'users/{user}',
            methods: ['GET'],
            name: null,
            segments: ['users', '{user}'],
            parameters: ['user'],
        );

        $config = new RuleConfig([], [], [], []);

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that a configured uncountable segment is never flagged even when singular.
     *
     * @return void
     */
    public function testUncountableSegmentIsNotFlagged(): void
    {
        // Arrange — `media` is in the uncountables list; the fake inflector
        // would return false for isPlural('media'), but uncountable bypass fires first
        $route = new NormalisedRoute(
            uri: 'media/{item}',
            methods: ['GET'],
            name: null,
            segments: ['media', '{item}'],
            parameters: ['item'],
        );

        $config = new RuleConfig([], [], [], ['media']);

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that a standalone top-level singular segment (no following param) is flagged.
     *
     * @return void
     */
    public function testTopLevelSingularCollectionIsFlagged(): void
    {
        // Arrange — `user` is the final literal segment with no following param
        $route = new NormalisedRoute(
            uri: 'user',
            methods: ['GET'],
            name: null,
            segments: ['user'],
            parameters: [],
        );

        $config = new RuleConfig([], [], [], []);

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('user', $violations[0]->offendingSurface);
    }

    /**
     * Test that a standalone top-level plural segment produces no violation.
     *
     * @return void
     */
    public function testTopLevelPluralCollectionIsNotFlagged(): void
    {
        // Arrange
        $route = new NormalisedRoute(
            uri: 'users',
            methods: ['GET'],
            name: null,
            segments: ['users'],
            parameters: [],
        );

        $config = new RuleConfig([], [], [], []);

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that route parameter segments are never evaluated as collection segments.
     *
     * @return void
     */
    public function testParameterSegmentsAreIgnored(): void
    {
        // Arrange — a lone parameter with no preceding literal
        $route = new NormalisedRoute(
            uri: '{user}',
            methods: ['GET'],
            name: null,
            segments: ['{user}'],
            parameters: ['user'],
        );

        $config = new RuleConfig([], [], [], []);

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert
        static::assertEmpty($violations);
    }

    /**
     * Test that rule id() returns 'R4' and severity() returns Severity::ERROR.
     *
     * @return void
     */
    public function testRuleMetadata(): void
    {
        static::assertSame('R4', $this->rule->id());
        static::assertSame(Severity::ERROR, $this->rule->severity());
    }

    /**
     * Build a fake Inflector that treats words ending in 's' as plural and
     * everything else as singular. This is sufficient for fixture segments.
     *
     * @return \SineMacula\ApiToolkit\RouteLinting\Contracts\Inflector
     */
    private function makeInflector(): Inflector
    {
        return new class implements Inflector {
            /**
             * Return the singular form of a value.
             *
             * @param  string  $value
             * @return string
             */
            public function singular(string $value): string
            {
                return rtrim($value, 's');
            }

            /**
             * Determine whether the value is plural.
             *
             * @param  string  $value
             * @return bool
             */
            public function isPlural(string $value): bool
            {
                return str_ends_with($value, 's');
            }
        };
    }
}
