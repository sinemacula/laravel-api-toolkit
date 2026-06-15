<?php

namespace Tests\Unit\RouteLinting\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Contracts\Inflector;
use SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig;
use SineMacula\ApiToolkit\RouteLinting\NormalisedRoute;
use SineMacula\ApiToolkit\RouteLinting\Rules\Support\SegmentNormaliser;
use SineMacula\ApiToolkit\RouteLinting\Rules\Support\VerbDenylist;
use SineMacula\ApiToolkit\RouteLinting\Rules\VerbInPathRule;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use SineMacula\ApiToolkit\RouteLinting\Violation;
use Tests\TestCase;

/**
 * Tests for VerbInPathRule (R1).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(VerbInPathRule::class)]
class VerbInPathRuleTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\RouteLinting\Rules\Support\SegmentNormaliser */
    private SegmentNormaliser $normaliser;

    /** @var \SineMacula\ApiToolkit\RouteLinting\Rules\Support\VerbDenylist */
    private VerbDenylist $denylist;

    /** @var \SineMacula\ApiToolkit\RouteLinting\Rules\VerbInPathRule */
    private VerbInPathRule $rule;

    /**
     * Set up a stub inflector, normaliser, denylist, and rule before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Stub inflector: strips a trailing 's' to singularise, returns word unchanged otherwise
        $inflector = new class implements Inflector {
            /**
             * @param  string  $word
             * @return string
             */
            public function singular(string $word): string
            {
                return str_ends_with($word, 's') ? substr($word, 0, -1) : $word;
            }

            /**
             * @param  string  $word
             * @return bool
             */
            public function isPlural(string $word): bool
            {
                return str_ends_with($word, 's');
            }
        };

        $this->normaliser = new SegmentNormaliser($inflector);

        $this->denylist = new VerbDenylist(
            ['get', 'create', 'cancel', 'login', 'logout', 'delete', 'fetch'],
            [
                'get'    => 'Use GET /resources instead.',
                'create' => 'Use POST /resources instead.',
                'cancel' => 'Use DELETE /resources/{id} instead.',
                'login'  => 'Use POST /auth instead.',
                'logout' => 'Use DELETE /auth/session instead.',
                'delete' => 'Use DELETE /resources/{id} instead.',
                'fetch'  => 'Use GET /resources instead.',
            ],
        );

        $this->rule = new VerbInPathRule($this->normaliser, $this->denylist);
    }

    /**
     * Test that id() returns 'R1' and severity() returns Severity::ERROR.
     *
     * @return void
     */
    public function testIdAndSeverity(): void
    {
        static::assertSame('R1', $this->rule->id());
        static::assertSame(Severity::ERROR, $this->rule->severity());
    }

    /**
     * Test that /getUsers produces one R1 error violation naming 'get' with a non-null remediation hint.
     *
     * @return void
     */
    public function testFlagsGetUsersWithRemediationHint(): void
    {
        // Arrange
        $route  = $this->makeRoute('getUsers');
        $config = $this->makeConfig();

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert — one violation for the verb 'get'; 'user' is not a verb
        static::assertCount(1, $violations);

        $violation = $violations[0];

        static::assertInstanceOf(Violation::class, $violation);
        static::assertSame('R1', $violation->ruleId);
        static::assertSame(Severity::ERROR, $violation->severity);
        static::assertSame('get', $violation->offendingSurface);
        static::assertNotNull($violation->remediationHint);
    }

    /**
     * Test that /users/create, /order/{id}/cancel, and /login each produce an R1 violation naming the offending verb.
     *
     * @return void
     */
    public function testFlagsCreateCancelAndLogin(): void
    {
        // Arrange
        $config = $this->makeConfig();

        // Act & Assert — /users/create
        $createViolations = $this->rule->inspect($this->makeRoute('users/create'), $config);
        static::assertCount(1, $createViolations);
        static::assertSame('create', $createViolations[0]->offendingSurface);
        static::assertSame('R1', $createViolations[0]->ruleId);

        // Act & Assert — /order/{id}/cancel
        $cancelViolations = $this->rule->inspect($this->makeRoute('order/{id}/cancel'), $config);
        static::assertCount(1, $cancelViolations);
        static::assertSame('cancel', $cancelViolations[0]->offendingSurface);

        // Act & Assert — /login
        $loginViolations = $this->rule->inspect($this->makeRoute('login'), $config);
        static::assertCount(1, $loginViolations);
        static::assertSame('login', $loginViolations[0]->offendingSurface);
    }

    /**
     * Test that a clean plural collection /users produces no R1 violation.
     *
     * @return void
     */
    public function testCleanPluralCollectionNotFlagged(): void
    {
        // Arrange — /users normalises to 'user', which is not a verb
        $route  = $this->makeRoute('users');
        $config = $this->makeConfig();

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert
        static::assertSame([], $violations);
    }

    /**
     * Test that the emitted violation's remediationHint is non-null for a denylisted verb with a configured hint.
     *
     * @return void
     */
    public function testRemediationHintIsPresentForDenylistedVerb(): void
    {
        // Arrange
        $route  = $this->makeRoute('login');
        $config = $this->makeConfig();

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert
        static::assertCount(1, $violations);
        static::assertNotNull($violations[0]->remediationHint);
        static::assertSame('Use POST /auth instead.', $violations[0]->remediationHint);
    }

    /**
     * Test that a route with the same verb appearing in two segments emits a single R1 violation for it.
     *
     * @return void
     */
    public function testDuplicateVerbEmittedOncePerRoute(): void
    {
        // Arrange — 'getItems/getDetails' would normalise 'get' twice
        $route  = $this->makeRoute('getItems/getDetails');
        $config = $this->makeConfig();

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert — 'get' appears in both segments but must be emitted once only
        $offendingSurfaces = array_map(fn (Violation $v): string => $v->offendingSurface, $violations);
        static::assertCount(1, array_filter($offendingSurfaces, fn (string $s): bool => $s === 'get'));
    }

    /**
     * Test that a denylisted verb appearing in two separate segments produces exactly one R1 violation.
     *
     * Kills the `$seen[$word] = true` → `false` dedup mutant: under the mutant the
     * seen-flag is stored as false so the guard `$seen[$word] ?? false` always reads
     * false, allowing the same verb to be emitted once per segment it appears in.
     * Asserting `assertCount(1, $violations)` on a route where `get` normalises from
     * two distinct segments catches the double-emission introduced by the mutant.
     *
     * @return void
     */
    public function testSameVerbInTwoSegmentsProducesExactlyOneViolation(): void
    {
        // Arrange — 'get-users/get-items' decomposes to words [get, user, get, item];
        // 'get' appears twice but must produce exactly one R1 violation
        $route  = $this->makeRoute('get-users/get-items');
        $config = $this->makeConfig();

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert — total violation count must be exactly 1 (deduplicated)
        static::assertCount(1, $violations);
        static::assertSame('get', $violations[0]->offendingSurface);
        static::assertSame('R1', $violations[0]->ruleId);
    }

    /**
     * Test that two distinct denylisted verbs in the same route each produce their own R1 violation.
     *
     * Kills the ArrayOneItem mutant (#84): when a route produces two or more violations,
     * the mutant returns only the first element. Asserting both violations by count and
     * by their exact offendingSurface values forces the full array to be present.
     *
     * @return void
     */
    public function testTwoDistinctVerbsProduceTwoViolations(): void
    {
        // Arrange — 'getUsers/deleteItems' normalises to words including 'get' and 'delete',
        // both of which are in the denylist
        $route  = $this->makeRoute('getUsers/deleteItems');
        $config = $this->makeConfig();

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert — exactly two violations, one per distinct verb
        static::assertCount(2, $violations);

        $surfaces = array_map(fn (Violation $v): string => $v->offendingSurface, $violations);
        static::assertContains('get', $surfaces);
        static::assertContains('delete', $surfaces);

        // Both must carry R1 ERROR severity
        foreach ($violations as $violation) {
            static::assertSame('R1', $violation->ruleId);
            static::assertSame(Severity::ERROR, $violation->severity);
        }
    }

    /**
     * Test that a route consisting only of parameters normalises to empty and produces no violations.
     *
     * @return void
     */
    public function testParameterOnlyRouteProducesNoViolations(): void
    {
        // Arrange
        $route  = $this->makeRoute('{user}');
        $config = $this->makeConfig();

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert
        static::assertSame([], $violations);
    }

    /**
     * Test that the routeIdentity on the emitted violation matches the route's identity() value.
     *
     * @return void
     */
    public function testViolationCarriesCorrectRouteIdentity(): void
    {
        // Arrange
        $route  = $this->makeRoute('login');
        $config = $this->makeConfig();

        // Act
        $violations = $this->rule->inspect($route, $config);

        // Assert
        static::assertCount(1, $violations);
        static::assertSame('GET login', $violations[0]->routeIdentity);
    }

    /**
     * Build a minimal NormalisedRoute for the given URI.
     *
     * @param  string  $uri
     * @return \SineMacula\ApiToolkit\RouteLinting\NormalisedRoute
     */
    private function makeRoute(string $uri): NormalisedRoute
    {
        return new NormalisedRoute(
            uri: $uri,
            methods: ['GET'],
            name: null,
            segments: explode('/', $uri),
            parameters: [],
        );
    }

    /**
     * Build a minimal RuleConfig with empty uncountables.
     *
     * @return \SineMacula\ApiToolkit\RouteLinting\Dto\RuleConfig
     */
    private function makeConfig(): RuleConfig
    {
        return new RuleConfig(
            verbDenylist: [],
            remediationHints: [],
            exemptions: [],
            uncountables: [],
        );
    }
}
