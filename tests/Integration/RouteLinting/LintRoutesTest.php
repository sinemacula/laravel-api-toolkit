<?php

namespace Tests\Integration\RouteLinting;

use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Configuration\ConfigRuleConfiguration;
use SineMacula\ApiToolkit\RouteLinting\Inflection\FrameworkInflector;
use SineMacula\ApiToolkit\RouteLinting\LintRoutes;
use SineMacula\ApiToolkit\RouteLinting\RouteLintEngine;
use SineMacula\ApiToolkit\RouteLinting\RouteLintReport;
use SineMacula\ApiToolkit\RouteLinting\Rules\ApiResourceAlignmentRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\KebabCaseRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\LowercaseRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\NestingDepthRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\PluralCollectionsRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\RouteNameRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\SlashSanityRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\StandardMethodsRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\Support\SegmentNormaliser;
use SineMacula\ApiToolkit\RouteLinting\Rules\Support\VerbDenylist;
use SineMacula\ApiToolkit\RouteLinting\Rules\VerbInPathRule;
use SineMacula\ApiToolkit\RouteLinting\Sources\RouterRouteSource;
use Tests\TestCase;

/**
 * End-to-end integration tests for the LintRoutes use case.
 *
 * Drives the use case against a fixture route table registered on the booted
 * framework router. Config is seeded via `config()->set()` so each test can
 * control the verb denylist, exemptions, and uncountables independently.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LintRoutes::class)]
class LintRoutesTest extends TestCase
{
    /**
     * Default verb denylist that covers the fixture offenders used by most tests.
     *
     * @var array<int, string>
     */
    private const VERB_DENYLIST = [
        'get', 'list', 'create', 'add', 'update', 'edit', 'delete',
        'remove', 'cancel', 'login', 'logout', 'search', 'fetch',
        'transfer', 'check', 'process', 'submit',
    ];

    /**
     * Test that the five common offenders are flagged by defaults and /users is clean (TAC-15-01).
     *
     * Fixture routes: /getUsers, /users/create, /order/{id}/cancel, /login,
     * /userProfiles each produce at least one violation; /users produces none.
     *
     * @return void
     */
    public function testFlagsCommonOffendersWithDefaults(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        $router->get('getUsers', fn () => [])->name('get-users');
        $router->post('users/create', fn () => [])->name('users.create-action');
        $router->post('order/{id}/cancel', fn () => [])->name('order.cancel');
        $router->post('login', fn () => [])->name('auth.login');
        $router->get('userProfiles', fn () => [])->name('user-profiles');
        $router->get('users', fn () => [])->name('users.index');

        $report = $this->buildUseCase($router)->lint();

        $offendingIdentities = $this->collectOffendingIdentities($report);

        static::assertNotEmpty($report->errors(), 'Expected at least one error-severity violation.');

        // Each offending route must have at least one finding
        static::assertTrue($this->hasViolationForUri($report, 'getUsers'), '/getUsers should be flagged');
        static::assertTrue($this->hasViolationForUri($report, 'users/create'), '/users/create should be flagged');
        static::assertTrue($this->hasViolationForUri($report, 'order/{id}/cancel'), '/order/{id}/cancel should be flagged');
        static::assertTrue($this->hasViolationForUri($report, 'login'), '/login should be flagged');
        static::assertTrue($this->hasViolationForUri($report, 'userProfiles'), '/userProfiles should be flagged');

        // The control route must be clean
        static::assertNotContains('GET,HEAD users users.index', $offendingIdentities, '/users should be clean');
    }

    /**
     * Test that an exempted violating route has its violation suppressed and is not stale (TAC-15-02).
     *
     * @return void
     */
    public function testExemptedViolationIsSuppressed(): void
    {
        $this->seedDefaultConfig([
            [
                'match'  => 'login',
                'reason' => 'Legacy endpoint kept for backward compatibility.',
            ],
        ]);

        $router = $this->getRouter();
        $router->post('login', fn () => [])->name('auth.login');

        $report = $this->buildUseCase($router)->lint();

        // The /login route is violating but covered by an allowlist entry — no errors expected
        static::assertSame([], $report->errors(), 'Violation for exempted route should be suppressed.');

        // The allowlist entry matched a live route — no stale waivers
        static::assertSame([], $report->staleWaivers(), 'Matched entry must not appear as stale.');
    }

    /**
     * Test that an allowlist entry matching no live route appears in staleWaivers() (TAC-15-03).
     *
     * @return void
     */
    public function testStaleWaiverIsReported(): void
    {
        $this->seedDefaultConfig([
            [
                'match'  => 'no-such-route',
                'reason' => 'Was needed for the old API; route no longer exists.',
            ],
        ]);

        $router = $this->getRouter();
        $router->get('users', fn () => [])->name('users.index');

        $report = $this->buildUseCase($router)->lint();

        static::assertContains('no-such-route', $report->staleWaivers(), 'Unmatched entry must be reported as stale.');
    }

    /**
     * Test that removing a verb from the denylist clears the R1 finding without creating an exemption (TAC-15-04).
     *
     * Removing `transfer` from the denylist means /transfers is clean for R1;
     * no allowlist entry is configured, so staleWaivers() stays empty.
     *
     * @return void
     */
    public function testHomographRemovalClearsVerbFindingWithoutExemption(): void
    {
        // Denylist WITHOUT 'transfer' — it was removed as a homograph
        $denylistWithoutTransfer = array_values(array_filter(
            self::VERB_DENYLIST,
            fn (string $v): bool => $v !== 'transfer',
        ));

        $this->seedDefaultConfig([], $denylistWithoutTransfer);

        $router = $this->getRouter();
        $router->get('transfers', fn () => [])->name('transfers.index');

        $report = $this->buildUseCase($router)->lint();

        // No R1 violation for /transfers because 'transfer' was removed from the denylist
        $r1Violations = array_filter($report->errors(), fn ($v) => $v->ruleId === 'R1');

        static::assertSame([], array_values($r1Violations), 'No R1 violation expected after homograph removal.');

        // No exemption entry was created — allowlist stays empty, no stale waivers
        static::assertSame([], $report->staleWaivers(), 'No exemption entry should be stale when using denylist tuning.');
    }

    /**
     * Test that an inline suppression on a route drops exactly that rule's error while
     * a different rule on the same route still reports (per-rule inline suppression).
     *
     * Route /getUsers triggers R1 (verb in path) and R2 (camelCase).
     * Suppressing only R1 inline leaves R2 still reported.
     *
     * @return void
     */
    public function testInlineSuppressionDropsOnlyTargetedRule(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        $router->get('getUsers', fn () => [])
            ->name('get-users')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint(['R1'], 'Verb in path is intentional for this legacy endpoint.');

        $report = $this->buildUseCase($router)->lint();

        // R1 is suppressed inline; it must not appear in the report
        $r1Violations = array_values(array_filter($report->errors(), fn ($v) => $v->ruleId === 'R1'));
        static::assertSame([], $r1Violations, 'R1 must be suppressed by the inline suppression.');

        // R2 (camelCase) is NOT covered by the inline suppression and must still be reported
        $r2Violations = array_values(array_filter(
            array_merge($report->errors(), $report->warnings()),
            fn ($v) => $v->ruleId === 'R2',
        ));
        static::assertNotEmpty($r2Violations, 'R2 must still be reported on the same route.');
    }

    /**
     * Test that a config allowlist entry with an explicit rules list suppresses
     * only the listed rules (per-rule config suppression).
     *
     * @return void
     */
    public function testConfigExemptionWithRulesIsPerRule(): void
    {
        $this->seedDefaultConfig([
            [
                'match'  => 'login',
                'reason' => 'Legacy auth endpoint; R1 only waived.',
                'rules'  => ['R1'],
            ],
        ]);

        $router = $this->getRouter();
        $router->post('login', fn () => [])->name('auth.login');

        $report = $this->buildUseCase($router)->lint();

        // R1 is waived by the config entry; it must not appear in errors
        $r1Violations = array_values(array_filter($report->errors(), fn ($v) => $v->ruleId === 'R1'));
        static::assertSame([], $r1Violations, 'R1 must be suppressed by the config exemption.');

        // Other rules on /login are NOT waived and must still appear
        $otherViolations = array_values(array_filter(
            array_merge($report->errors(), $report->warnings()),
            fn ($v) => $v->ruleId !== 'R1',
        ));
        static::assertNotEmpty($otherViolations, 'Non-R1 violations on /login must still be reported.');
    }

    /**
     * Test that an inline suppression that suppresses no violation produces a
     * stale entry on the report (unused inline suppression detection).
     *
     * Route /users is clean; the inline suppression for R1 fires on nothing.
     *
     * @return void
     */
    public function testUnusedInlineSuppressionIsReportedAsStale(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        $router->get('users', fn () => [])
            ->name('users.index')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint(['R1'], 'Pre-emptive suppression that turns out to be unnecessary.');

        $report = $this->buildUseCase($router)->lint();

        // The route is clean (no R1 violation), so the inline suppression is stale
        $staleWaivers = $report->staleWaivers();
        static::assertNotEmpty($staleWaivers, 'An unused inline suppression must appear as a stale entry.');

        $staleString = implode(' ', $staleWaivers);
        static::assertStringContainsString('suppressed nothing', $staleString);
        static::assertStringContainsString('Pre-emptive suppression that turns out to be unnecessary.', $staleString);
    }

    /**
     * Test that a config entry without a rules list suppresses all violations on
     * its route (backward-compatibility: omitting rules means all rules).
     *
     * @return void
     */
    public function testConfigExemptionWithoutRulesSuppressesAllViolations(): void
    {
        $this->seedDefaultConfig([
            [
                'match'  => 'login',
                'reason' => 'Legacy endpoint kept for backward compatibility.',
            ],
        ]);

        $router = $this->getRouter();
        $router->post('login', fn () => [])->name('auth.login');

        $report = $this->buildUseCase($router)->lint();

        // All violations on /login are suppressed; no errors or warnings expected for that route
        $loginViolations = array_values(array_filter(
            array_merge($report->errors(), $report->warnings()),
            fn ($v) => str_contains($v->routeIdentity, 'login'),
        ));
        static::assertSame([], $loginViolations, 'All violations on an all-rules-waived route must be suppressed.');

        // The allowlist entry matched a live route and suppressed violations — not stale
        static::assertSame([], $report->staleWaivers(), 'A used config entry must not appear as stale.');
    }

    /**
     * Test that two runs over the same route table and config produce byte-identical verdicts (TAC-15-05 / NFR-01).
     *
     * @return void
     */
    public function testRepeatableVerdictAcrossTwoRuns(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        $router->get('getUsers', fn () => [])->name('get-users');
        $router->post('users/create', fn () => [])->name('users.create-action');
        $router->get('users', fn () => [])->name('users.index');

        $useCase = $this->buildUseCase($router);

        $firstReport  = $useCase->lint();
        $secondReport = $useCase->lint();

        static::assertSame(
            $this->serialiseReport($firstReport),
            $this->serialiseReport($secondReport),
            'Two runs over the same inputs must produce identical verdicts (NFR-01).',
        );
    }

    /**
     * Build the LintRoutes use case with real adapters against the given router.
     *
     * Constructs the full collaborator graph directly (no container) so the test
     * is self-contained and free of binding-registrar dependencies from other tasks.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @return \SineMacula\ApiToolkit\RouteLinting\LintRoutes
     */
    private function buildUseCase(Router $router): LintRoutes
    {
        $inflector = new FrameworkInflector;

        $engine = new RouteLintEngine(
            new VerbInPathRule(new SegmentNormaliser($inflector), new VerbDenylist(
                config('api-toolkit.route_linting.verb_denylist', []),
                config('api-toolkit.route_linting.remediation_hints', []),
            )),
            new KebabCaseRule,
            new LowercaseRule,
            new PluralCollectionsRule($inflector),
            new SlashSanityRule,
            new StandardMethodsRule,
            new RouteNameRule,
            new ApiResourceAlignmentRule,
            new NestingDepthRule,
        );

        return new LintRoutes(
            new RouterRouteSource($router),
            new ConfigRuleConfiguration,
            $engine,
        );
    }

    /**
     * Seed the route_linting config section used by ConfigRuleConfiguration.
     *
     * @param  array<int, array<string, string>>  $exemptions
     * @param  array<int, string>|null  $verbDenylist
     * @return void
     */
    private function seedDefaultConfig(array $exemptions = [], ?array $verbDenylist = null): void
    {
        config()->set('api-toolkit.route_linting.verb_denylist', $verbDenylist ?? self::VERB_DENYLIST);
        config()->set('api-toolkit.route_linting.remediation_hints', []);
        config()->set('api-toolkit.route_linting.exemptions', $exemptions);
        config()->set('api-toolkit.route_linting.uncountables', []);
    }

    /**
     * Get a fresh router instance bound to the booted application.
     *
     * @return \Illuminate\Routing\Router
     */
    private function getRouter(): Router
    {
        assert($this->app !== null);

        /** @var \Illuminate\Routing\Router */
        return $this->app->make('router');
    }

    /**
     * Collect the distinct route-identity strings that have at least one violation in the report.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\RouteLintReport  $report
     * @return array<int, string>
     */
    private function collectOffendingIdentities(RouteLintReport $report): array
    {
        $identities = [];

        foreach (array_merge($report->errors(), $report->warnings()) as $violation) {
            $identities[$violation->routeIdentity] = true;
        }

        return array_keys($identities);
    }

    /**
     * Determine whether any violation in the report references the given URI.
     *
     * Matches by checking whether the route identity string contains the URI
     * segment (the identity format is `METHODS uri [name]`).
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\RouteLintReport  $report
     * @param  string  $uri
     * @return bool
     */
    private function hasViolationForUri(RouteLintReport $report, string $uri): bool
    {
        foreach (array_merge($report->errors(), $report->warnings()) as $violation) {
            if (str_contains($violation->routeIdentity, ' ' . $uri . ' ') || str_ends_with($violation->routeIdentity, ' ' . $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Serialise a RouteLintReport to a stable string for byte-identical comparison.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\RouteLintReport  $report
     * @return string
     */
    private function serialiseReport(RouteLintReport $report): string
    {
        $lines = [];

        foreach ($report->errors() as $v) {
            $lines[] = 'E|' . $v->ruleId . '|' . $v->routeIdentity . '|' . $v->offendingSurface;
        }

        foreach ($report->warnings() as $v) {
            $lines[] = 'W|' . $v->ruleId . '|' . $v->routeIdentity . '|' . $v->offendingSurface;
        }

        foreach ($report->staleWaivers() as $key) {
            $lines[] = 'S|' . $key;
        }

        return implode("\n", $lines);
    }
}
