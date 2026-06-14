<?php

namespace Tests\Unit\RouteLinting;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Contracts\Rule;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\RouteLintEngine;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use SineMacula\ApiToolkit\RouteLinting\Violation;
use Tests\TestCase;

/**
 * Tests for the RouteLintEngine pure orchestrator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RouteLintEngine::class)]
class RouteLintEngineTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute */
    private NormalisedRoute $route;

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

        $this->route = new NormalisedRoute(
            uri: 'users',
            methods: ['GET'],
            name: 'users.index',
            segments: ['users'],
            parameters: [],
        );
        $this->config = new RuleConfig([], [], [], []);
    }

    /**
     * Test that the engine runs all supplied rules in their constructor-supplied
     * order and returns both violations in that order.
     *
     * @return void
     */
    public function testRunsAllRulesInSuppliedOrder(): void
    {
        // Arrange — two stub rules each emitting a canned violation
        $firstViolation  = new Violation('R1', Severity::ERROR, 'GET users users.index', 'getUsers', null);
        $secondViolation = new Violation('R2', Severity::ERROR, 'GET users users.index', 'UserProfiles', null);

        $firstRule = new class ($firstViolation) implements Rule {
            /**
             * @param  \SineMacula\ApiToolkit\RouteLinting\Violation  $violation
             */
            public function __construct(private readonly Violation $violation) {}

            /**
             * @return string
             */
            #[\Override]
            public function id(): string
            {
                return 'R1';
            }

            /**
             * @return \SineMacula\ApiToolkit\RouteLinting\Severity
             */
            #[\Override]
            public function severity(): Severity
            {
                return Severity::ERROR;
            }

            /**
             * @param  \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute  $route
             * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig  $config
             * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Violation>
             */
            #[\Override]
            public function inspect(NormalisedRoute $route, RuleConfig $config): array
            {
                return [$this->violation];
            }
        };

        $secondRule = new class ($secondViolation) implements Rule {
            /**
             * @param  \SineMacula\ApiToolkit\RouteLinting\Violation  $violation
             */
            public function __construct(private readonly Violation $violation) {}

            /**
             * @return string
             */
            #[\Override]
            public function id(): string
            {
                return 'R2';
            }

            /**
             * @return \SineMacula\ApiToolkit\RouteLinting\Severity
             */
            #[\Override]
            public function severity(): Severity
            {
                return Severity::ERROR;
            }

            /**
             * @param  \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute  $route
             * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig  $config
             * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Violation>
             */
            #[\Override]
            public function inspect(NormalisedRoute $route, RuleConfig $config): array
            {
                return [$this->violation];
            }
        };

        $engine = new RouteLintEngine($firstRule, $secondRule);

        // Act
        $violations = $engine->inspect($this->route, $this->config);

        // Assert — both violations present in supplied rule order
        static::assertCount(2, $violations);
        static::assertSame('R1', $violations[0]->ruleId);
        static::assertSame('R2', $violations[1]->ruleId);
    }

    /**
     * Test that violations from multiple rules with different yield counts are
     * flattened into a single array of the expected total size.
     *
     * Rule A emits 0, Rule B emits 1, Rule C emits 2 — total must be 3.
     *
     * @return void
     */
    public function testAggregatesViolationsAcrossRules(): void
    {
        // Arrange
        $violationB  = new Violation('R2', Severity::ERROR, 'GET users users.index', 'UserProfiles', null);
        $violationC1 = new Violation('R3', Severity::ERROR, 'GET users users.index', 'Users', null);
        $violationC2 = new Violation('R3', Severity::ERROR, 'GET users users.index', 'USERS', null);

        $ruleA = new class implements Rule {
            /**
             * @return string
             */
            #[\Override]
            public function id(): string
            {
                return 'R1';
            }

            /**
             * @return \SineMacula\ApiToolkit\RouteLinting\Severity
             */
            #[\Override]
            public function severity(): Severity
            {
                return Severity::ERROR;
            }

            /**
             * @param  \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute  $route
             * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig  $config
             * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Violation>
             */
            #[\Override]
            public function inspect(NormalisedRoute $route, RuleConfig $config): array
            {
                return [];
            }
        };

        $ruleB = new class ($violationB) implements Rule {
            /**
             * @param  \SineMacula\ApiToolkit\RouteLinting\Violation  $violation
             */
            public function __construct(private readonly Violation $violation) {}

            /**
             * @return string
             */
            #[\Override]
            public function id(): string
            {
                return 'R2';
            }

            /**
             * @return \SineMacula\ApiToolkit\RouteLinting\Severity
             */
            #[\Override]
            public function severity(): Severity
            {
                return Severity::ERROR;
            }

            /**
             * @param  \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute  $route
             * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig  $config
             * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Violation>
             */
            #[\Override]
            public function inspect(NormalisedRoute $route, RuleConfig $config): array
            {
                return [$this->violation];
            }
        };

        $ruleC = new class ($violationC1, $violationC2) implements Rule {
            /**
             * @param  \SineMacula\ApiToolkit\RouteLinting\Violation  $violation1
             * @param  \SineMacula\ApiToolkit\RouteLinting\Violation  $violation2
             */
            public function __construct(
                private readonly Violation $violation1,
                private readonly Violation $violation2,
            ) {}

            /**
             * @return string
             */
            #[\Override]
            public function id(): string
            {
                return 'R3';
            }

            /**
             * @return \SineMacula\ApiToolkit\RouteLinting\Severity
             */
            #[\Override]
            public function severity(): Severity
            {
                return Severity::ERROR;
            }

            /**
             * @param  \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute  $route
             * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig  $config
             * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Violation>
             */
            #[\Override]
            public function inspect(NormalisedRoute $route, RuleConfig $config): array
            {
                return [$this->violation1, $this->violation2];
            }
        };

        $engine = new RouteLintEngine($ruleA, $ruleB, $ruleC);

        // Act
        $violations = $engine->inspect($this->route, $this->config);

        // Assert — 0 + 1 + 2 = 3 violations in a flat array
        static::assertCount(3, $violations);
        static::assertSame('R2', $violations[0]->ruleId);
        static::assertSame('R3', $violations[1]->ruleId);
        static::assertSame('R3', $violations[2]->ruleId);
    }

    /**
     * Test that an engine constructed with no rules returns an empty array.
     *
     * @return void
     */
    public function testNoRulesReturnsEmpty(): void
    {
        // Arrange
        $engine = new RouteLintEngine;

        // Act
        $violations = $engine->inspect($this->route, $this->config);

        // Assert
        static::assertSame([], $violations);
    }

    /**
     * Test that calling inspect() twice with the same route and config produces
     * byte-identical arrays (NFR-01 repeatability / determinism).
     *
     * @return void
     */
    public function testRepeatableOutputAcrossTwoRuns(): void
    {
        // Arrange — a single stub rule emitting two canned violations
        $violation1 = new Violation('R1', Severity::ERROR, 'GET users users.index', 'getUsers', null);
        $violation2 = new Violation('R1', Severity::ERROR, 'GET users users.index', 'listUsers', null);

        $rule = new class ($violation1, $violation2) implements Rule {
            /**
             * @param  \SineMacula\ApiToolkit\RouteLinting\Violation  $violation1
             * @param  \SineMacula\ApiToolkit\RouteLinting\Violation  $violation2
             */
            public function __construct(
                private readonly Violation $violation1,
                private readonly Violation $violation2,
            ) {}

            /**
             * @return string
             */
            #[\Override]
            public function id(): string
            {
                return 'R1';
            }

            /**
             * @return \SineMacula\ApiToolkit\RouteLinting\Severity
             */
            #[\Override]
            public function severity(): Severity
            {
                return Severity::ERROR;
            }

            /**
             * @param  \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute  $route
             * @param  \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig  $config
             * @return array<int, \SineMacula\ApiToolkit\RouteLinting\Violation>
             */
            #[\Override]
            public function inspect(NormalisedRoute $route, RuleConfig $config): array
            {
                return [$this->violation1, $this->violation2];
            }
        };

        $engine = new RouteLintEngine($rule);

        // Act — two separate calls with identical inputs
        $firstRun  = $engine->inspect($this->route, $this->config);
        $secondRun = $engine->inspect($this->route, $this->config);

        // Assert — identical count, identical element identity per position
        static::assertCount(2, $firstRun);
        static::assertCount(2, $secondRun);

        foreach ($firstRun as $index => $violation) {
            static::assertSame($violation->ruleId, $secondRun[$index]->ruleId);
            static::assertSame($violation->routeIdentity, $secondRun[$index]->routeIdentity);
            static::assertSame($violation->offendingSurface, $secondRun[$index]->offendingSurface);
            static::assertSame($violation->severity, $secondRun[$index]->severity);
        }
    }
}
